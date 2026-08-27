<?php
/**
 * Kurage DB Agent — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * Claude Code や Claude Desktop などのAIエージェントから、kdbagent.php の
 * 「宣言した表・列・操作だけ」という制限を通してデータベースを操作させるための橋渡し。
 *
 * 生SQLは一切通しません。ツールとして公開するのは kdbagent.php のコマンドだけで、
 * 範囲の判定は今までどおり kdbagent.php と設定ファイルが行います。
 *
 * 設置:
 *   1. kdbagent.php と同じフォルダにこのファイルを置く
 *   2. Claude Code に登録:
 *        claude mcp add kdbagent -- php /path/to/kdbagent_mcp.php
 *      Claude Desktop の場合は claude_desktop_config.json に:
 *        {"mcpServers":{"kdbagent":{"command":"php","args":["/path/to/kdbagent_mcp.php"]}}}
 *
 * 書き込みを禁止したい場合:
 *   KDBA_MCP_READONLY=1 を環境変数に設定すると、insert/update/delete を公開しません。
 *        claude mcp add kdbagent -e KDBA_MCP_READONLY=1 -- php /path/to/kdbagent_mcp.php
 *
 * プロトコル: MCP stdio transport（JSON-RPC 2.0 / 1行1メッセージ）。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('KDBA_MCP_VERSION', '1.0.0');
define('KDBA_CLI', __DIR__ . '/kdbagent.php');
$READONLY = (getenv('KDBA_MCP_READONLY') === '1');

if (!is_file(KDBA_CLI)) {
    fwrite(STDERR, "kdbagent.php が同じフォルダにありません: " . KDBA_CLI . "\n");
    exit(1);
}

/** kdbagent.php をコマンドとして呼び、JSON出力をそのまま受け取る */
function kdba_run(array $args)
{
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(KDBA_CLI);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
    $desc = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $p = proc_open($cmd, $desc, $pipes, __DIR__);
    if (!is_resource($p)) { return array(false, 'kdbagent.php を起動できませんでした'); }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($p);
    $text = trim($out) !== '' ? trim($out) : trim($err);
    $j = json_decode($text, true);
    if (is_array($j)) { return array(!empty($j['ok']), $text); }
    return array(false, $text !== '' ? $text : '応答がありませんでした');
}

/** --set col=val の並びを組み立てる（値の検証はkdbagent.php側が行う） */
function kdba_set_args($values)
{
    $args = array();
    if (is_array($values)) {
        foreach ($values as $col => $val) {
            if (is_array($val) || is_object($val)) { $val = json_encode($val, JSON_UNESCAPED_UNICODE); }
            $args[] = '--set';
            $args[] = $col . '=' . $val;
        }
    }
    return $args;
}

$TOOLS_READ = array(
    array(
        'name' => 'kdb_tables',
        'description' => '操作できる表の一覧と、各表で触れる列・検索対象・編集可否を返す。他のツールを使う前に必ずこれで範囲を確認すること。宣言されていない表や列は存在しないものとして扱う。',
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass(), 'required' => array()),
    ),
    array(
        'name' => 'kdb_schema',
        'description' => '指定した表の列構成・検索対象列・編集可能列・削除可否を返す。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array('table' => array('type' => 'string', 'description' => '表の名前（kdb_tablesで得た名前）')),
            'required' => array('table'),
        ),
    ),
    array(
        'name' => 'kdb_select',
        'description' => '表から行を検索して返す。searchはキーワード検索（検索対象に宣言された列を横断）、whereは列の完全一致による絞り込み。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'table'  => array('type' => 'string', 'description' => '表の名前'),
                'search' => array('type' => 'string', 'description' => 'キーワード検索の文字列（任意）'),
                'where'  => array('type' => 'object', 'description' => '列名と値の完全一致条件（任意）。例: {"status":"active"}'),
                'limit'  => array('type' => 'integer', 'description' => '取得件数の上限（任意）'),
                'offset' => array('type' => 'integer', 'description' => '取得開始位置（任意）'),
            ),
            'required' => array('table'),
        ),
    ),
    array(
        'name' => 'kdb_get',
        'description' => '主キーを指定して1行だけ取得する。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'table' => array('type' => 'string', 'description' => '表の名前'),
                'id'    => array('type' => 'string', 'description' => '主キーの値'),
            ),
            'required' => array('table', 'id'),
        ),
    ),
);

$TOOLS_WRITE = array(
    array(
        'name' => 'kdb_insert',
        'description' => '表に1行追加する。編集可能と宣言された列だけが指定できる。実行前に、追加する内容を利用者に確認すること。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'table'  => array('type' => 'string', 'description' => '表の名前'),
                'values' => array('type' => 'object', 'description' => '列名と値の組。例: {"name":"山田太郎","email":"yamada@example.jp"}'),
            ),
            'required' => array('table', 'values'),
        ),
    ),
    array(
        'name' => 'kdb_update',
        'description' => '主キーで指定した1行を更新する。編集可能と宣言された列だけが変更できる。実行前に、変更前後の値を利用者に確認すること。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'table'  => array('type' => 'string', 'description' => '表の名前'),
                'id'     => array('type' => 'string', 'description' => '主キーの値'),
                'values' => array('type' => 'object', 'description' => '変更する列名と値の組'),
            ),
            'required' => array('table', 'id', 'values'),
        ),
    ),
    array(
        'name' => 'kdb_delete',
        'description' => '主キーで指定した1行を削除する。削除が許可された表でのみ実行できる。取り消せない操作なので、実行前に必ず利用者の同意を得ること。',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'table' => array('type' => 'string', 'description' => '表の名前'),
                'id'    => array('type' => 'string', 'description' => '主キーの値'),
            ),
            'required' => array('table', 'id'),
        ),
    ),
);

$TOOLS = $READONLY ? $TOOLS_READ : array_merge($TOOLS_READ, $TOOLS_WRITE);

/** ツール名 → kdbagent.php のコマンド引数へ変換して実行 */
function kdba_call_tool($name, $a, $readonly)
{
    $table = isset($a['table']) ? (string)$a['table'] : '';
    switch ($name) {
        case 'kdb_tables':
            return kdba_run(array('tables'));
        case 'kdb_schema':
            return kdba_run(array('schema', $table));
        case 'kdb_select':
            $args = array('select', $table);
            if (isset($a['search']) && $a['search'] !== '') { $args[] = '--search'; $args[] = $a['search']; }
            if (isset($a['where']) && is_array($a['where'])) {
                foreach ($a['where'] as $c => $v) { $args[] = '--where'; $args[] = $c . '=' . $v; }
            }
            if (isset($a['limit']))  { $args[] = '--limit';  $args[] = (int)$a['limit']; }
            if (isset($a['offset'])) { $args[] = '--offset'; $args[] = (int)$a['offset']; }
            return kdba_run($args);
        case 'kdb_get':
            return kdba_run(array('get', $table, '--id', isset($a['id']) ? $a['id'] : ''));
        case 'kdb_insert':
            if ($readonly) { return array(false, '読み取り専用モードのため書き込みはできません'); }
            return kdba_run(array_merge(array('insert', $table), kdba_set_args(isset($a['values']) ? $a['values'] : array())));
        case 'kdb_update':
            if ($readonly) { return array(false, '読み取り専用モードのため書き込みはできません'); }
            return kdba_run(array_merge(array('update', $table, '--id', isset($a['id']) ? $a['id'] : ''),
                kdba_set_args(isset($a['values']) ? $a['values'] : array())));
        case 'kdb_delete':
            if ($readonly) { return array(false, '読み取り専用モードのため書き込みはできません'); }
            return kdba_run(array('delete', $table, '--id', isset($a['id']) ? $a['id'] : ''));
    }
    return array(false, '不明なツールです: ' . $name);
}

function kdba_send($msg)
{
    echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
}

function kdba_result($id, $result) { kdba_send(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result)); }
function kdba_error($id, $code, $message)
{
    kdba_send(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message)));
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }

    $id     = isset($req['id']) ? $req['id'] : null;
    $method = isset($req['method']) ? $req['method'] : '';
    $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : array();

    // 通知（idなし）は応答しない
    if ($id === null && strpos($method, 'notifications/') === 0) { continue; }

    switch ($method) {
        case 'initialize':
            $client = isset($params['protocolVersion']) ? (string)$params['protocolVersion'] : '2024-11-05';
            kdba_result($id, array(
                'protocolVersion' => $client,
                'capabilities'    => array('tools' => new stdClass()),
                'serverInfo'      => array('name' => 'kdbagent', 'version' => KDBA_MCP_VERSION),
                'instructions'    => '宣言された表・列・操作だけを扱うデータベース窓口です。まず kdb_tables で触れる範囲を確認してください。生SQLは実行できません。データを書き換える前に、利用者に内容を確認してください。',
            ));
            break;

        case 'ping':
            kdba_result($id, new stdClass());
            break;

        case 'tools/list':
            kdba_result($id, array('tools' => $GLOBALS['TOOLS']));
            break;

        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
            $known = false;
            foreach ($GLOBALS['TOOLS'] as $t) { if ($t['name'] === $name) { $known = true; break; } }
            if (!$known) {
                kdba_result($id, array(
                    'content' => array(array('type' => 'text', 'text' => 'このサーバーでは使えないツールです: ' . $name)),
                    'isError' => true,
                ));
                break;
            }
            list($ok, $text) = kdba_call_tool($name, $args, $GLOBALS['READONLY']);
            kdba_result($id, array(
                'content' => array(array('type' => 'text', 'text' => $text)),
                'isError' => !$ok,
            ));
            break;

        default:
            if ($id !== null) { kdba_error($id, -32601, 'Method not found: ' . $method); }
    }
}
