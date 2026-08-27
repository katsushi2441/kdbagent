<?php
/**
 * Kurage DB Agent — MCPサーバー（リモート版・1ファイル・依存ライブラリなし）
 *
 * サーバーに設置済みの kdbagent.php を、HTTP API 越しに Claude Code / Codex などの
 * AIエージェントへ繋ぐ。手元のPCに kdbagent 本体やDB接続情報を置く必要はない。
 *
 * ローカル版（kdbagent_mcp.php）との違い:
 *   ローカル版 = 同じサーバーの中で kdbagent.php をコマンドとして呼ぶ
 *   リモート版 = 別のサーバーで動いている kdbagent.php を HTTPS で呼ぶ  ← これ
 *
 * サーバー側の準備:
 *   kdbagent_config.php に APIトークンを設定する（未設定だとHTTP APIは無効）
 *     define('KDBA_API_TOKEN', '（推測されない長い文字列）');
 *
 * 手元のPCでの登録:
 *   claude mcp add kdbagent \
 *     -e KDBA_URL=https://example.com/kdbagent/kdbagent.php \
 *     -e KDBA_TOKEN=（サーバーに設定したトークン） \
 *     -- php /path/to/kdbagent_mcp_remote.php
 *
 *   Codex CLI の場合は ~/.codex/config.toml に:
 *     [mcp_servers.kdbagent]
 *     command = "php"
 *     args = ["/path/to/kdbagent_mcp_remote.php"]
 *     env = { KDBA_URL = "https://example.com/kdbagent/kdbagent.php", KDBA_TOKEN = "..." }
 *
 * 書き込みを禁止する場合: KDBA_MCP_READONLY=1
 *
 * プロトコル: MCP stdio transport（JSON-RPC 2.0 / 1行1メッセージ）。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('KDBA_MCP_VERSION', '1.0.0');

$URL   = getenv('KDBA_URL') ?: '';
$TOKEN = getenv('KDBA_TOKEN') ?: '';
$READONLY = (getenv('KDBA_MCP_READONLY') === '1');

if ($URL === '' || $TOKEN === '') {
    fwrite(STDERR, "KDBA_URL と KDBA_TOKEN を環境変数で渡してください。\n"
        . "例: claude mcp add kdbagent -e KDBA_URL=https://example.com/kdbagent/kdbagent.php -e KDBA_TOKEN=xxxx -- php " . __FILE__ . "\n");
    exit(1);
}
if (stripos($URL, 'https://') !== 0 && stripos($URL, 'http://127.0.0.1') !== 0 && stripos($URL, 'http://localhost') !== 0) {
    fwrite(STDERR, "KDBA_URL は https:// で指定してください（トークンが平文で流れるため）。\n");
    exit(1);
}

/** サーバーの kdbagent.php をHTTP APIとして呼ぶ */
function kdba_api(array $fields)
{
    $sep = (strpos($GLOBALS['URL'], '?') === false) ? '?' : '&';
    $ch = curl_init($GLOBALS['URL'] . $sep . 'api=1');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => array('X-KDBA-Token: ' . $GLOBALS['TOKEN']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return array(false, json_encode(array('ok' => false, 'error' => 'サーバーに繋がりません: ' . $err), JSON_UNESCAPED_UNICODE));
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        // HTMLが返る = URLがkdbagent.phpを指していない等
        return array(false, json_encode(array('ok' => false,
            'error' => 'APIの応答がJSONではありません（HTTP ' . $code . '）。KDBA_URLがkdbagent.phpを指しているか確認してください。'),
            JSON_UNESCAPED_UNICODE));
    }
    if ($code === 401) {
        return array(false, json_encode(array('ok' => false, 'error' => 'トークンが違います（サーバー側のKDBA_API_TOKENと一致していません）'), JSON_UNESCAPED_UNICODE));
    }
    return array(!empty($j['ok']), json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
        'inputSchema' => array('type' => 'object',
            'properties' => array('table' => array('type' => 'string', 'description' => '表の名前（kdb_tablesで得た名前）')),
            'required' => array('table')),
    ),
    array(
        'name' => 'kdb_select',
        'description' => '表から行を検索して返す。searchはキーワード検索（検索対象に宣言された列を横断）、whereは列の完全一致による絞り込み。',
        'inputSchema' => array('type' => 'object',
            'properties' => array(
                'table'  => array('type' => 'string', 'description' => '表の名前'),
                'search' => array('type' => 'string', 'description' => 'キーワード検索の文字列（任意）'),
                'where'  => array('type' => 'object', 'description' => '列名と値の完全一致条件（任意）'),
                'limit'  => array('type' => 'integer', 'description' => '取得件数の上限（任意）'),
                'offset' => array('type' => 'integer', 'description' => '取得開始位置（任意）'),
            ),
            'required' => array('table')),
    ),
    array(
        'name' => 'kdb_get',
        'description' => '主キーを指定して1行だけ取得する。',
        'inputSchema' => array('type' => 'object',
            'properties' => array(
                'table' => array('type' => 'string', 'description' => '表の名前'),
                'id'    => array('type' => 'string', 'description' => '主キーの値'),
            ),
            'required' => array('table', 'id')),
    ),
);

$TOOLS_WRITE = array(
    array(
        'name' => 'kdb_insert',
        'description' => '表に1行追加する。編集可能と宣言された列だけが指定できる。実行前に、追加する内容を利用者に確認すること。',
        'inputSchema' => array('type' => 'object',
            'properties' => array(
                'table'  => array('type' => 'string', 'description' => '表の名前'),
                'values' => array('type' => 'object', 'description' => '列名と値の組'),
            ),
            'required' => array('table', 'values')),
    ),
    array(
        'name' => 'kdb_update',
        'description' => '主キーで指定した1行を更新する。編集可能と宣言された列だけが変更できる。実行前に、変更前後の値を利用者に確認すること。',
        'inputSchema' => array('type' => 'object',
            'properties' => array(
                'table'  => array('type' => 'string', 'description' => '表の名前'),
                'id'     => array('type' => 'string', 'description' => '主キーの値'),
                'values' => array('type' => 'object', 'description' => '変更する列名と値の組'),
            ),
            'required' => array('table', 'id', 'values')),
    ),
    array(
        'name' => 'kdb_delete',
        'description' => '主キーで指定した1行を削除する。削除が許可された表でのみ実行できる。取り消せない操作なので、実行前に必ず利用者の同意を得ること。',
        'inputSchema' => array('type' => 'object',
            'properties' => array(
                'table' => array('type' => 'string', 'description' => '表の名前'),
                'id'    => array('type' => 'string', 'description' => '主キーの値'),
            ),
            'required' => array('table', 'id')),
    ),
);

$TOOLS = $READONLY ? $TOOLS_READ : array_merge($TOOLS_READ, $TOOLS_WRITE);

function kdba_call_tool($name, $a, $readonly)
{
    $table = isset($a['table']) ? (string)$a['table'] : '';
    switch ($name) {
        case 'kdb_tables':
            return kdba_api(array('cmd' => 'tables'));
        case 'kdb_schema':
            return kdba_api(array('cmd' => 'schema', 'table' => $table));
        case 'kdb_select':
            $f = array('cmd' => 'select', 'table' => $table);
            if (isset($a['search']) && $a['search'] !== '') { $f['search'] = $a['search']; }
            if (isset($a['where']) && is_array($a['where'])) { $f['where'] = $a['where']; }
            if (isset($a['limit']))  { $f['limit']  = (int)$a['limit']; }
            if (isset($a['offset'])) { $f['offset'] = (int)$a['offset']; }
            return kdba_api($f);
        case 'kdb_get':
            return kdba_api(array('cmd' => 'get', 'table' => $table, 'id' => isset($a['id']) ? $a['id'] : ''));
        case 'kdb_insert':
            if ($readonly) { return array(false, '読み取り専用モードのため書き込みはできません'); }
            return kdba_api(array('cmd' => 'insert', 'table' => $table, 'set' => isset($a['values']) ? (array)$a['values'] : array()));
        case 'kdb_update':
            if ($readonly) { return array(false, '読み取り専用モードのため書き込みはできません'); }
            return kdba_api(array('cmd' => 'update', 'table' => $table, 'id' => isset($a['id']) ? $a['id'] : '',
                'set' => isset($a['values']) ? (array)$a['values'] : array()));
        case 'kdb_delete':
            if ($readonly) { return array(false, '読み取り専用モードのため書き込みはできません'); }
            return kdba_api(array('cmd' => 'delete', 'table' => $table, 'id' => isset($a['id']) ? $a['id'] : ''));
    }
    return array(false, '不明なツールです: ' . $name);
}

function kdba_send($m) { echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; flush(); }
function kdba_result($id, $r) { kdba_send(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $r)); }

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    $id = isset($req['id']) ? $req['id'] : null;
    $method = isset($req['method']) ? $req['method'] : '';
    $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : array();
    if ($id === null && strpos($method, 'notifications/') === 0) { continue; }

    switch ($method) {
        case 'initialize':
            kdba_result($id, array(
                'protocolVersion' => isset($params['protocolVersion']) ? (string)$params['protocolVersion'] : '2024-11-05',
                'capabilities'    => array('tools' => new stdClass()),
                'serverInfo'      => array('name' => 'kdbagent', 'version' => KDBA_MCP_VERSION),
                'instructions'    => '宣言された表・列・操作だけを扱うデータベース窓口です（サーバー上のkdbagentにHTTPSで接続）。まず kdb_tables で触れる範囲を確認してください。生SQLは実行できません。データを書き換える前に、利用者に確認してください。',
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
                kdba_result($id, array('content' => array(array('type' => 'text', 'text' => 'このサーバーでは使えないツールです: ' . $name)), 'isError' => true));
                break;
            }
            list($ok, $text) = kdba_call_tool($name, $args, $GLOBALS['READONLY']);
            kdba_result($id, array('content' => array(array('type' => 'text', 'text' => $text)), 'isError' => !$ok));
            break;
        default:
            if ($id !== null) {
                kdba_send(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => -32601, 'message' => 'Method not found: ' . $method)));
            }
    }
}
