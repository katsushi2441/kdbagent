<?php
/**
 * Kurage DB Agent — 1ファイルの、範囲を絞った安全なDB管理ツール。
 *
 * 同じこのファイルが3つの顔を持ちます。どれも「設定で宣言した表・列・
 * 編集可否」という同じ制限を通ります。
 *
 *   1. ブラウザ         … 人が使う管理画面（ログイン・CSRF・監査ログ）
 *   2. `php kdbagent.php …`  … サーバー上のコマンド。JSONを返す。
 *                          Claude Code などのエージェントがそのまま叩ける
 *   3. `?api=1` + トークン … 外部からのJSON API（HTTP）
 *
 * 【安全の芯】
 * - SQLは全部PDOのプリペアドステートメント。値は必ずbindする
 * - 表名・列名は「設定に宣言されているか」を必ず照合してから使う。
 *   利用者が送ってきた文字列をそのままSQLの識別子にしない
 * - 宣言していない表・列・操作(挿入/更新/削除)はどの顔からも実行できない
 *
 * PHP 5.6 以降。依存ライブラリなし（PDO の sqlite / mysql ドライバのみ）。
 */

$KDBA_DIR = __DIR__;
$cfg = __DIR__ . '/kdbagent_config.php';
if (!is_file($cfg)) {
    $msg = 'kdbagent_config.php がありません。kdbagent_config.php.example をコピーして作成してください。';
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg . "\n"); exit(2); }
    http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); echo $msg; exit;
}
require $cfg;

if (!defined('KDBA_TITLE'))         { define('KDBA_TITLE', 'データ管理'); }
if (!defined('KDBA_PASSWORD_HASH')) { define('KDBA_PASSWORD_HASH', ''); }
if (!defined('KDBA_API_TOKEN'))     { define('KDBA_API_TOKEN', ''); }
if (!defined('KDBA_AUDIT_LOG'))     { define('KDBA_AUDIT_LOG', __DIR__ . '/kdba_data/audit.log'); }

/* 表示言語。'ja'（既定）か 'en'。設定に define('KDBA_LANG', 'en'); を書くと英語になる。 */
if (!defined('KDBA_LANG')) { define('KDBA_LANG', getenv('KDBA_LANG') === 'en' ? 'en' : 'ja'); }

/**
 * 画面とエラーの文言。英語で使う場合のためだけに持つ。
 * キーは日本語をそのまま使い、辞書に無ければ日本語を返す（訳し漏れで壊れない）。
 */
function kdba_t($ja, $arg = '')
{
    static $en = array(
        'その表は許可されていません: '     => 'This table is not allowed: ',
        'その列は許可されていません: '     => 'This column is not allowed: ',
        'この表は追加が許可されていません' => 'Inserting is not allowed for this table',
        'この表は更新が許可されていません' => 'Updating is not allowed for this table',
        'この表は削除が許可されていません' => 'Deleting is not allowed for this table',
        'トークンが違います'               => 'Invalid token',
        'HTTP APIは無効です（設定でトークンを設定してください）' => 'The HTTP API is disabled. Set KDBA_API_TOKEN in your config to enable it.',
        '未知のcmd: '                      => 'Unknown cmd: ',
        'パスワードが違います。'           => 'Wrong password.',
        'ログイン'   => 'Sign in',
        'ログアウト' => 'Sign out',
        '検索'       => 'Search',
        '編集'       => 'Edit',
        '保存'       => 'Save',
        '削除'       => 'Delete',
        '追加'       => 'Add',
        '新規追加'   => 'Add new',
        '閉じる'     => 'Close',
        '開く'       => 'Open',
        '解除'       => 'Clear',
        '表'         => 'Table',
        '列'         => 'Column',
        '件'         => ' rows',
        '／ 接続'    => ' / connection',
        'を開きます。' => ' will open.',
        'データ管理' => 'Data Manager',
    );
    $out = (KDBA_LANG === 'en' && isset($en[$ja])) ? $en[$ja] : $ja;
    return $out . $arg;
}

/* ============================================================
 * データ層（すべての顔が通る。ここが安全性の本体）
 * ========================================================== */

function kdba_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** random_bytes は PHP7+。5.6でも動くよう、無ければ openssl で代替する。 */
if (!function_exists('kdba_random_bytes')) {
    function kdba_random_bytes($n) {
        if (function_exists('random_bytes')) { return random_bytes($n); }
        if (function_exists('openssl_random_pseudo_bytes')) { return openssl_random_pseudo_bytes($n); }
        $s = '';
        for ($i = 0; $i < $n; $i++) { $s .= chr(mt_rand(0, 255)); }
        return $s;
    }
}


/** 例外にせず、呼び出し側で扱えるエラーの形。 */
class KdbaError extends Exception {}

/** 表の定義を取り出す。宣言に無ければ例外（＝触らせない）。 */
function kdba_table_def($key) {
    $all = kdba_tables();
    if (!isset($all[(string)$key])) {
        throw new KdbaError(kdba_t('その表は許可されていません: ', $key));
    }
    $d = $all[(string)$key];
    // 既定値を補う
    $d += array('label' => $key, 'search' => array(), 'editable' => array(),
                'can_insert' => false, 'can_update' => false, 'can_delete' => false,
                'order' => '', 'limit' => 100, 'table' => $key);
    return $d;
}

/** 列が、その表の宣言済み列に含まれているか。含まれなければ例外。 */
function kdba_assert_column($def, $col, $pool = 'columns') {
    $list = isset($def[$pool]) ? $def[$pool] : array();
    if (!in_array((string)$col, $list, true)) {
        throw new KdbaError(kdba_t('その列は許可されていません: ', $col));
    }
    return (string)$col;
}

/** 識別子（設定由来の表名・列名のみ）を引用符でくくる。 */
function kdba_quote_ident($id) {
    // 念のため。設定由来なので通常は英数字と_のみ
    return '`' . str_replace('`', '``', (string)$id) . '`';
}

/** 接続をPDOで開く（1接続1回だけ）。 */
function kdba_pdo($conn_name) {
    static $pool = array();
    $conn_name = (string)$conn_name;
    if (isset($pool[$conn_name])) { return $pool[$conn_name]; }

    $conns = kdba_connections();
    if (!isset($conns[$conn_name])) {
        throw new KdbaError('接続が定義されていません: ' . $conn_name);
    }
    $c = $conns[$conn_name];
    $driver = isset($c['driver']) ? $c['driver'] : '';

    if ($driver === 'sqlite') {
        $path = isset($c['path']) ? $c['path'] : '';
        if ($path === '') { throw new KdbaError('sqlite の path がありません'); }
        $dsn = 'sqlite:' . $path;
        $pdo = new PDO($dsn, null, null);
    } elseif ($driver === 'mysql') {
        $host    = isset($c['host'])    ? $c['host']    : 'localhost';
        $port    = isset($c['port'])    ? (int)$c['port'] : 3306;
        $dbname  = isset($c['dbname'])  ? $c['dbname']  : '';
        $charset = isset($c['charset']) ? $c['charset'] : 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $pdo = new PDO($dsn, isset($c['user']) ? $c['user'] : '',
                             isset($c['pass']) ? $c['pass'] : '');
    } else {
        throw new KdbaError('未対応のdriverです: ' . $driver);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pool[$conn_name] = $pdo;
    return $pdo;
}

/** ORDER BY を安全に組み立てる。設定の固定文字列だが、列名を照合して通す。 */
function kdba_order_sql($def) {
    $order = trim((string)$def['order']);
    if ($order === '') { return ''; }
    $parts = array();
    foreach (explode(',', $order) as $seg) {
        $seg = trim($seg);
        if ($seg === '') { continue; }
        $bits = preg_split('/\s+/', $seg);
        $col = kdba_assert_column($def, $bits[0], 'columns');
        $dir = (isset($bits[1]) && strtoupper($bits[1]) === 'DESC') ? 'DESC' : 'ASC';
        $parts[] = kdba_quote_ident($col) . ' ' . $dir;
    }
    return $parts ? (' ORDER BY ' . implode(', ', $parts)) : '';
}

/**
 * 検索・絞り込みつきで行を取る。
 *   $opts: where(列=>値・列はcolumnsに限る) / search(search列へのLIKE) /
 *          limit / offset
 */
function kdba_select($key, $opts = array()) {
    $def = kdba_table_def($key);
    $pdo = kdba_pdo($def['conn']);

    $cols = array();
    foreach ($def['columns'] as $c) { $cols[] = kdba_quote_ident($c); }
    $sql = 'SELECT ' . implode(', ', $cols) . ' FROM ' . kdba_quote_ident($def['table']);

    $wheres = array(); $bind = array();
    if (!empty($opts['where']) && is_array($opts['where'])) {
        foreach ($opts['where'] as $col => $val) {
            $col = kdba_assert_column($def, $col, 'columns');
            $wheres[] = kdba_quote_ident($col) . ' = ?';
            $bind[] = $val;
        }
    }
    if (isset($opts['search']) && $opts['search'] !== '' && $def['search']) {
        $ors = array();
        foreach ($def['search'] as $col) {
            $ors[] = kdba_quote_ident($col) . ' LIKE ?';
            $bind[] = '%' . $opts['search'] . '%';
        }
        $wheres[] = '(' . implode(' OR ', $ors) . ')';
    }
    if ($wheres) { $sql .= ' WHERE ' . implode(' AND ', $wheres); }

    $sql .= kdba_order_sql($def);

    $limit = isset($opts['limit']) ? (int)$opts['limit'] : (int)$def['limit'];
    if ($limit < 1)    { $limit = 1; }
    if ($limit > 1000) { $limit = 1000; }
    $offset = isset($opts['offset']) ? max(0, (int)$opts['offset']) : 0;
    // LIMIT/OFFSET は整数に確定済みなので直接埋める（プレースホルダだと
    // ドライバによって型が揺れるため）
    $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;

    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll();
}

/** 主キーで1行。 */
function kdba_row($key, $pk_val) {
    $def = kdba_table_def($key);
    $pk = kdba_assert_column($def, $def['pk'], 'columns');
    $rows = kdba_select($key, array('where' => array($pk => $pk_val), 'limit' => 1));
    return $rows ? $rows[0] : null;
}

/** 入力の列を editable に絞り込む（宣言外は捨てる）。 */
function kdba_filter_editable($def, $data) {
    $out = array();
    foreach ((array)$data as $col => $val) {
        if (in_array((string)$col, $def['editable'], true)) {
            $out[(string)$col] = $val;
        }
    }
    return $out;
}

function kdba_insert($key, $data, $actor = 'cli') {
    $def = kdba_table_def($key);
    if (empty($def['can_insert'])) { throw new KdbaError(kdba_t('この表は追加が許可されていません')); }
    $data = kdba_filter_editable($def, $data);
    if (!$data) { throw new KdbaError('追加する値がありません（editableの列のみ受け付けます）'); }

    $cols = array(); $ph = array(); $bind = array();
    foreach ($data as $col => $val) {
        $cols[] = kdba_quote_ident($col); $ph[] = '?'; $bind[] = $val;
    }
    $pdo = kdba_pdo($def['conn']);
    $sql = 'INSERT INTO ' . kdba_quote_ident($def['table'])
         . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $id = $pdo->lastInsertId();
    kdba_audit($actor, 'insert', $key, $id, $data);
    return array('inserted_id' => $id);
}

function kdba_update($key, $pk_val, $data, $actor = 'cli') {
    $def = kdba_table_def($key);
    if (empty($def['can_update'])) { throw new KdbaError(kdba_t('この表は更新が許可されていません')); }
    $pk = kdba_assert_column($def, $def['pk'], 'columns');
    $data = kdba_filter_editable($def, $data);
    if (!$data) { throw new KdbaError('更新する値がありません（editableの列のみ受け付けます）'); }

    $sets = array(); $bind = array();
    foreach ($data as $col => $val) {
        $sets[] = kdba_quote_ident($col) . ' = ?'; $bind[] = $val;
    }
    $bind[] = $pk_val;
    $pdo = kdba_pdo($def['conn']);
    $sql = 'UPDATE ' . kdba_quote_ident($def['table'])
         . ' SET ' . implode(', ', $sets) . ' WHERE ' . kdba_quote_ident($pk) . ' = ?';
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    kdba_audit($actor, 'update', $key, $pk_val, $data);
    return array('updated' => $st->rowCount());
}

function kdba_delete($key, $pk_val, $actor = 'cli') {
    $def = kdba_table_def($key);
    if (empty($def['can_delete'])) { throw new KdbaError(kdba_t('この表は削除が許可されていません')); }
    $pk = kdba_assert_column($def, $def['pk'], 'columns');
    $pdo = kdba_pdo($def['conn']);
    $sql = 'DELETE FROM ' . kdba_quote_ident($def['table'])
         . ' WHERE ' . kdba_quote_ident($pk) . ' = ?';
    $st = $pdo->prepare($sql);
    $st->execute(array($pk_val));
    kdba_audit($actor, 'delete', $key, $pk_val, null);
    return array('deleted' => $st->rowCount());
}

/** 表の構造（列・編集可否・操作可否）を返す。エージェントが最初に読む用。 */
function kdba_schema($key = null) {
    if ($key !== null) {
        $def = kdba_table_def($key);
        return array(
            'key' => (string)$key, 'label' => $def['label'], 'pk' => $def['pk'],
            'columns' => $def['columns'], 'search' => $def['search'],
            'editable' => $def['editable'],
            'can_insert' => (bool)$def['can_insert'],
            'can_update' => (bool)$def['can_update'],
            'can_delete' => (bool)$def['can_delete'],
        );
    }
    $out = array();
    foreach (array_keys(kdba_tables()) as $k) { $out[] = kdba_schema($k); }
    return $out;
}

/** 監査ログ。失敗しても本体は止めない（ログのために操作を消さない）。 */
function kdba_audit($actor, $action, $key, $pk, $detail) {
    $line = json_encode(array(
        'at'     => date('c'),
        'actor'  => (string)$actor,
        'action' => (string)$action,
        'table'  => (string)$key,
        'pk'     => is_scalar($pk) ? (string)$pk : $pk,
        'detail' => $detail,
        'ip'     => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $dir = dirname(KDBA_AUDIT_LOG);
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    @file_put_contents(KDBA_AUDIT_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
}

/* ============================================================
 * ここから下は「このファイルが入口のとき」だけ動かす。
 * テストが require したときは、上のデータ層の関数だけを使わせる。
 * ========================================================== */

$KDBA_IS_MAIN = (isset($_SERVER['SCRIPT_FILENAME'])
    && @realpath($_SERVER['SCRIPT_FILENAME']) === @realpath(__FILE__));
if (!$KDBA_IS_MAIN) { return; }

/* ============================================================
 * 顔①: コマンド（CLI）— Claude Code などが叩く。JSONを返す
 * ========================================================== */

if (PHP_SAPI === 'cli') {
    // 任意: サーバー側でトークンを要求したい場合は環境変数で
    $need = getenv('KDBA_REQUIRE_TOKEN');
    if ($need) {
        if (!hash_equals((string)$need, (string)getenv('KDBA_TOKEN'))) {
            fwrite(STDERR, "KDBA_TOKEN が一致しません\n"); exit(3);
        }
    }
    array_shift($argv); // スクリプト名
    $cmd = array_shift($argv);
    // --key=val / --key val / 位置引数 を素朴に解釈
    $opt = array(); $pos = array();
    for ($i = 0; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (strpos($a, '--') === 0) {
            $a = substr($a, 2);
            if (strpos($a, '=') !== false) { list($k, $v) = explode('=', $a, 2); $opt[$k][] = $v; }
            elseif ($i + 1 < count($argv) && strpos($argv[$i + 1], '--') !== 0) { $opt[$a][] = $argv[++$i]; }
            else { $opt[$a][] = true; }
        } else { $pos[] = $a; }
    }
    $first = function($k, $d = null) use ($opt) { return isset($opt[$k]) ? $opt[$k][0] : $d; };
    // --set col=val （複数可）を連想配列に
    $set = array();
    if (!empty($opt['set'])) {
        foreach ($opt['set'] as $pair) {
            if (strpos((string)$pair, '=') !== false) { list($c, $v) = explode('=', $pair, 2); $set[$c] = $v; }
        }
    }
    $where = array();
    if (!empty($opt['where'])) {
        foreach ($opt['where'] as $pair) {
            if (strpos((string)$pair, '=') !== false) { list($c, $v) = explode('=', $pair, 2); $where[$c] = $v; }
        }
    }

    $emit = function ($ok, $data) {
        echo json_encode(array('ok' => $ok) + $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit($ok ? 0 : 1);
    };
    try {
        $table = isset($pos[0]) ? $pos[0] : $first('table');
        switch ($cmd) {
            case 'tables':
            case 'schema':
                $emit(true, array('schema' => kdba_schema($table)));
                break;
            case 'select':
            case 'search':
                $rows = kdba_select($table, array(
                    'search' => $first('search', ''),
                    'where'  => $where,
                    'limit'  => $first('limit'),
                    'offset' => $first('offset'),
                ));
                $emit(true, array('table' => $table, 'count' => count($rows), 'rows' => $rows));
                break;
            case 'get':
                $emit(true, array('row' => kdba_row($table, $first('id'))));
                break;
            case 'insert':
                $emit(true, kdba_insert($table, $set, 'cli:' . (get_current_user() ?: 'local')));
                break;
            case 'update':
                $emit(true, kdba_update($table, $first('id'), $set, 'cli:' . (get_current_user() ?: 'local')));
                break;
            case 'delete':
                $emit(true, kdba_delete($table, $first('id'), 'cli:' . (get_current_user() ?: 'local')));
                break;
            default:
                fwrite(STDERR,
                    "Kurage DB Agent — コマンド\n" .
                    "  php kdbagent.php tables\n" .
                    "  php kdbagent.php schema <table>\n" .
                    "  php kdbagent.php select <table> [--search q] [--where col=val] [--limit N] [--offset N]\n" .
                    "  php kdbagent.php get    <table> --id <pk>\n" .
                    "  php kdbagent.php insert <table> --set col=val [--set ...]\n" .
                    "  php kdbagent.php update <table> --id <pk> --set col=val [--set ...]\n" .
                    "  php kdbagent.php delete <table> --id <pk>\n");
                exit(2);
        }
    } catch (Exception $e) {
        $emit(false, array('error' => $e->getMessage()));
    }
    exit;
}

/* ============================================================
 * ここから下は HTTP（ブラウザ / JSON API）
 * ========================================================== */

session_start();

/* ---- 顔③: JSON API（?api=1 + トークン）---- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $emit = function ($ok, $data) {
        http_response_code($ok ? 200 : 400);
        echo json_encode(array('ok' => $ok) + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };
    if (KDBA_API_TOKEN === '') { $emit(false, array('error' => kdba_t('HTTP APIは無効です（設定でトークンを設定してください）'))); }
    $tok = isset($_SERVER['HTTP_X_KDBA_TOKEN']) ? $_SERVER['HTTP_X_KDBA_TOKEN']
         : (isset($_GET['token']) ? $_GET['token'] : '');
    if (!hash_equals(KDBA_API_TOKEN, (string)$tok)) {
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => kdba_t('トークンが違います')));
        exit;
    }
    $in = $_POST + $_GET;
    $cmd = isset($in['cmd']) ? $in['cmd'] : 'schema';
    $table = isset($in['table']) ? $in['table'] : null;
    $set = isset($in['set']) && is_array($in['set']) ? $in['set'] : array();
    $where = isset($in['where']) && is_array($in['where']) ? $in['where'] : array();
    try {
        switch ($cmd) {
            case 'tables': case 'schema':
                $emit(true, array('schema' => kdba_schema($table))); break;
            case 'select': case 'search':
                $rows = kdba_select($table, array('search' => isset($in['search']) ? $in['search'] : '',
                    'where' => $where, 'limit' => isset($in['limit']) ? $in['limit'] : null,
                    'offset' => isset($in['offset']) ? $in['offset'] : null));
                $emit(true, array('count' => count($rows), 'rows' => $rows)); break;
            case 'get':
                $emit(true, array('row' => kdba_row($table, isset($in['id']) ? $in['id'] : null))); break;
            case 'insert':
                $emit(true, kdba_insert($table, $set, 'api')); break;
            case 'update':
                $emit(true, kdba_update($table, isset($in['id']) ? $in['id'] : null, $set, 'api')); break;
            case 'delete':
                $emit(true, kdba_delete($table, isset($in['id']) ? $in['id'] : null, 'api')); break;
            default:
                $emit(false, array('error' => kdba_t('未知のcmd: ', $cmd)));
        }
    } catch (Exception $e) { $emit(false, array('error' => $e->getMessage())); }
    exit;
}

/* ---- 顔②: ブラウザ管理画面 ---- */

function kdba_logged_in() {
    if (KDBA_PASSWORD_HASH === '') { return true; }  // 鍵なし運用
    return !empty($_SESSION['kdba_auth']);
}
if (empty($_SESSION['kdba_csrf'])) { $_SESSION['kdba_csrf'] = bin2hex(kdba_random_bytes(24)); }
$csrf = $_SESSION['kdba_csrf'];

$notice = ''; $error = '';

// ログイン
if (isset($_POST['login'])) {
    if (KDBA_PASSWORD_HASH !== '' && password_verify((string)(isset($_POST['password']) ? $_POST['password'] : ''), KDBA_PASSWORD_HASH)) {
        $_SESSION['kdba_auth'] = true;
        header('Location: ?'); exit;
    }
    $error = 'パスワードが違います。';
}
if (isset($_GET['logout'])) { unset($_SESSION['kdba_auth']); header('Location: ?'); exit; }

// 書き込み系（CSRF必須）
if (kdba_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do']) && hash_equals($csrf, (string)(isset($_POST['csrf']) ? $_POST['csrf'] : ''))) {
    $key = (string)(isset($_POST['table']) ? $_POST['table'] : '');
    $actor = 'web';
    try {
        if ($_POST['do'] === 'update') {
            $r = kdba_update($key, isset($_POST['id']) ? $_POST['id'] : null, isset($_POST['set']) ? $_POST['set'] : array(), $actor);
            $notice = '更新しました（' . (int)$r['updated'] . '件）。';
        } elseif ($_POST['do'] === 'insert') {
            $r = kdba_insert($key, isset($_POST['set']) ? $_POST['set'] : array(), $actor);
            $notice = '追加しました（id=' . kdba_h($r['inserted_id']) . '）。';
        } elseif ($_POST['do'] === 'delete') {
            $r = kdba_delete($key, isset($_POST['id']) ? $_POST['id'] : null, $actor);
            $notice = '削除しました（' . (int)$r['deleted'] . '件）。';
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$tables = kdba_tables();
$cur = isset($_GET['t']) && isset($tables[$_GET['t']]) ? (string)$_GET['t']
     : (array_key_exists(0, array_keys($tables)) ? (string)array_keys($tables)[0] : '');

?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo kdba_h(KDBA_TITLE); ?> | Kurage DB Agent</title>
<style>
:root{--ink:#1b2733;--sub:#5c6b7a;--bg:#f6fafa;--line:#d7e5e5;--teal:#0e8a80;--teal-d:#0a6b63;
  --card:#eef6f5;--warn:#c0392b;--gold-bg:#fbf4dd;--gold-line:#e8d7a4}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Hiragino Sans","Yu Gothic",Meiryo,sans-serif;color:var(--ink);background:var(--bg);
  line-height:1.7;font-size:14px}
a{color:var(--teal-d)}
header.top{background:#fff;border-bottom:1px solid var(--line);padding:11px 0}
.wrap{max-width:1080px;margin:0 auto;padding:0 18px}
header.top .wrap{display:flex;align-items:center;gap:12px}
header.top b{font-size:15px}
header.top nav{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.chip{font-size:12px;color:var(--sub);border:1px solid var(--line);border-radius:999px;padding:4px 12px;
  background:#fff;text-decoration:none}
.chip.on{background:var(--teal-d);color:#fff;border-color:var(--teal-d)}
main{padding:22px 0}
h1{font-size:20px;margin-bottom:4px}
.sub{color:var(--sub);font-size:12.5px;margin-bottom:16px}
.notice{background:var(--gold-bg);border:1px solid var(--gold-line);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px}
.err{background:#fdecea;border:1px solid #f5c6c2;color:#a3261b;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px}
.bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
input[type=text],input[type=search],input[type=password],textarea{font:inherit;font-size:14px;color:inherit;
  background:#fff;border:1.5px solid var(--line);border-radius:8px;padding:8px 11px}
textarea{width:100%;min-height:60px;resize:vertical}
.btn{border:0;border-radius:999px;padding:8px 18px;font-weight:800;font-size:13px;cursor:pointer;
  background:linear-gradient(135deg,var(--teal),var(--teal-d));color:#fff;text-decoration:none;display:inline-block}
.btn.ghost{background:none;color:var(--sub);border:1.5px solid var(--line)}
.btn.danger{background:none;color:var(--warn);border:1.5px solid #f0b8b2}
.scroll{overflow-x:auto;border:1px solid var(--line);border-radius:10px;background:#fff}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;white-space:nowrap;vertical-align:top}
th{background:var(--card);color:var(--sub);font-size:11.5px;font-weight:700;position:sticky;top:0}
td.act{white-space:nowrap}
.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-top:16px}
.card h2{font-size:15px;margin-bottom:10px}
.frow{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.frow label{width:150px;font-size:12.5px;color:var(--sub);font-weight:700}
.frow input,.frow textarea{flex:1;min-width:200px}
.login{max-width:340px;margin:60px auto;background:#fff;border:1px solid var(--line);border-radius:12px;padding:26px}
.pill{font-size:11px;color:var(--sub);border:1px solid var(--line);border-radius:999px;padding:1px 8px}
footer{color:var(--sub);font-size:11.5px;padding:24px 0 40px;text-align:center}
code{background:var(--card);border-radius:5px;padding:1px 6px;font-size:.92em}
</style>
</head>
<body>
<header class="top"><div class="wrap">
  <b>🪼 <?php echo kdba_h(KDBA_TITLE); ?></b>
  <span class="pill">Kurage DB Agent</span>
  <nav>
    <?php if (kdba_logged_in()): foreach ($tables as $k => $d): ?>
      <a class="chip <?php echo $k === $cur ? 'on' : ''; ?>" href="?t=<?php echo kdba_h($k); ?>"><?php
        echo kdba_h(isset($d['label']) ? $d['label'] : $k); ?></a>
    <?php endforeach; if (KDBA_PASSWORD_HASH !== ''): ?>
      <a class="chip" href="?logout=1">ログアウト</a>
    <?php endif; endif; ?>
  </nav>
</div></header>

<main><div class="wrap">
<?php if ($notice !== ''): ?><div class="notice"><?php echo $notice; ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?php echo kdba_h($error); ?></div><?php endif; ?>

<?php if (!kdba_logged_in()): ?>
  <form method="post" class="login">
    <h1>ログイン</h1>
    <p class="sub"><?php echo kdba_h(KDBA_TITLE); ?> を開きます。</p>
    <input type="password" name="password" placeholder="パスワード" style="width:100%;margin-bottom:12px" autofocus>
    <button class="btn" type="submit" name="login" value="1" style="width:100%">開く</button>
  </form>

<?php elseif ($cur === ''): ?>
  <p class="sub">表が設定されていません。kdbagent_config.php の kdba_tables() をご確認ください。</p>

<?php else:
  $def = kdba_table_def($cur);
  $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
  try {
      $rows = kdba_select($cur, array('search' => $q));
      $load_err = '';
  } catch (Exception $e) { $rows = array(); $load_err = $e->getMessage(); }
  $edit_id = isset($_GET['edit']) ? $_GET['edit'] : null;
  $edit_row = ($edit_id !== null) ? kdba_row($cur, $edit_id) : null;
?>
  <h1><?php echo kdba_h($def['label']); ?></h1>
  <p class="sub">
    表 <code><?php echo kdba_h($def['table']); ?></code> ／ 接続 <code><?php echo kdba_h($def['conn']); ?></code> ／
    <?php echo count($def['columns']); ?>列
    <?php echo $def['can_insert'] ? '・追加可' : ''; ?><?php echo $def['can_update'] ? '・更新可' : ''; ?><?php echo $def['can_delete'] ? '・削除可' : ''; ?>
  </p>

  <?php if ($load_err !== ''): ?><div class="err"><?php echo kdba_h($load_err); ?></div><?php endif; ?>

  <form method="get" class="bar">
    <input type="hidden" name="t" value="<?php echo kdba_h($cur); ?>">
    <?php if ($def['search']): ?>
      <input type="search" name="q" value="<?php echo kdba_h($q); ?>"
             placeholder="<?php echo kdba_h(implode('・', $def['search'])); ?> を検索" style="min-width:240px">
      <button class="btn ghost" type="submit">検索</button>
      <?php if ($q !== ''): ?><a class="chip" href="?t=<?php echo kdba_h($cur); ?>">解除</a><?php endif; ?>
    <?php endif; ?>
    <span class="sub" style="margin-left:auto"><?php echo count($rows); ?>件</span>
  </form>

  <div class="scroll"><table>
    <tr>
      <?php foreach ($def['columns'] as $c): ?><th><?php echo kdba_h($c); ?></th><?php endforeach; ?>
      <?php if ($def['can_update'] || $def['can_delete']): ?><th></th><?php endif; ?>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <?php foreach ($def['columns'] as $c): ?>
        <td><?php $v = isset($r[$c]) ? (string)$r[$c] : '';
                  echo kdba_h(mb_strimwidth($v, 0, 80, '…', 'UTF-8')); ?></td>
      <?php endforeach; ?>
      <?php if ($def['can_update'] || $def['can_delete']): ?>
      <td class="act">
        <?php if ($def['can_update']): ?>
          <a class="chip" href="?t=<?php echo kdba_h($cur); ?>&edit=<?php echo kdba_h($r[$def['pk']]); ?>">編集</a>
        <?php endif; ?>
        <?php if ($def['can_delete']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('この行を削除します。よろしいですか？')">
            <input type="hidden" name="csrf" value="<?php echo kdba_h($csrf); ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="table" value="<?php echo kdba_h($cur); ?>">
            <input type="hidden" name="id" value="<?php echo kdba_h($r[$def['pk']]); ?>">
            <button class="btn danger" type="submit" style="padding:4px 12px;font-size:11.5px">削除</button>
          </form>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows && $load_err === ''): ?>
      <tr><td colspan="<?php echo count($def['columns']) + 1; ?>" class="sub" style="text-align:center;padding:24px">
        <?php echo $q !== '' ? '該当する行がありません。' : '行がありません。'; ?></td></tr>
    <?php endif; ?>
  </table></div>

  <?php /* 編集フォーム */ if ($def['can_update'] && $edit_row): ?>
  <div class="card">
    <h2>編集（<?php echo kdba_h($def['pk']); ?> = <?php echo kdba_h($edit_id); ?>）</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo kdba_h($csrf); ?>">
      <input type="hidden" name="do" value="update">
      <input type="hidden" name="table" value="<?php echo kdba_h($cur); ?>">
      <input type="hidden" name="id" value="<?php echo kdba_h($edit_id); ?>">
      <?php foreach ($def['editable'] as $c): ?>
      <div class="frow">
        <label><?php echo kdba_h($c); ?></label>
        <?php $val = isset($edit_row[$c]) ? (string)$edit_row[$c] : ''; ?>
        <?php if (mb_strlen($val, 'UTF-8') > 60 || strpos($val, "\n") !== false): ?>
          <textarea name="set[<?php echo kdba_h($c); ?>]"><?php echo kdba_h($val); ?></textarea>
        <?php else: ?>
          <input type="text" name="set[<?php echo kdba_h($c); ?>]" value="<?php echo kdba_h($val); ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <p style="margin-top:12px">
        <button class="btn" type="submit">保存</button>
        <a class="btn ghost" href="?t=<?php echo kdba_h($cur); ?>">閉じる</a>
      </p>
    </form>
  </div>
  <?php endif; ?>

  <?php /* 追加フォーム */ if ($def['can_insert'] && !$edit_row): ?>
  <div class="card">
    <h2>新規追加</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo kdba_h($csrf); ?>">
      <input type="hidden" name="do" value="insert">
      <input type="hidden" name="table" value="<?php echo kdba_h($cur); ?>">
      <?php foreach ($def['editable'] as $c): ?>
      <div class="frow">
        <label><?php echo kdba_h($c); ?></label>
        <input type="text" name="set[<?php echo kdba_h($c); ?>]" value="">
      </div>
      <?php endforeach; ?>
      <p style="margin-top:12px"><button class="btn" type="submit">追加</button></p>
    </form>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div></main>
<footer>Kurage DB Agent ／ 株式会社エクスブリッジ ／ 設定で宣言した表・列だけを、人にもエージェントにも安全に。</footer>
</body>
</html>
