<?php
/**
 * Kurage DB Agent の検証。
 *
 * 一番大事なのは「宣言していない表・列・操作は、どの顔からも通らない」こと。
 * ここが緩いと、範囲を絞った安全なツールという製品の前提が崩れる。
 *
 * 一時ディレクトリに sqlite と設定を作り、
 *   1. データ層の関数を直接（require して）
 *   2. 実際のCLI入口を（サブプロセスで）
 * の両方で確かめる。本番の設定・DBには触らない。
 */

$tmp = sys_get_temp_dir() . '/kdba_check_' . getmypid();
@mkdir($tmp, 0700, true);
$core = dirname(__DIR__) . '/public/kdbagent.php';

$pass = 0; $fail = 0;
function ok($cond, $label, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($got === null ? '' : "  → " . var_export($got, true)) . "\n"; }
}

/* ---- 一時DB ---- */
$dbfile = $tmp . '/app.sqlite';
$pdo = new PDO('sqlite:' . $dbfile);
$pdo->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, email TEXT, phone TEXT, note TEXT, secret TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE secrets (id INTEGER PRIMARY KEY, value TEXT)");  // 宣言しない表
$stmt = $pdo->prepare("INSERT INTO customers (name,email,phone,note,secret,created_at) VALUES (?,?,?,?,?,?)");
foreach (array(
    array('田中太郎', 'tanaka@example.jp', '090-1111', 'VIP', 'TOP-SECRET', '2026-08-01'),
    array('鈴木花子', 'suzuki@example.jp', '090-2222', '',    'hidden',     '2026-08-02'),
    array("Robert'; DROP TABLE customers;--", 'bob@example.jp', '090-3333', '注入テスト', 'x', '2026-08-03'),
) as $r) { $stmt->execute($r); }
$pdo->exec("INSERT INTO secrets (id,value) VALUES (1,'絶対に見せない')");
$pdo = null;

/* ---- 一時設定（secret列は宣言しない・secrets表も宣言しない）---- */
$conf = <<<PHP
<?php
define('KDBA_TITLE', 'テスト');
define('KDBA_PASSWORD_HASH', '');
define('KDBA_API_TOKEN', 'tok-test-123');
define('KDBA_AUDIT_LOG', '$tmp/audit.log');
function kdba_connections() {
    return array('main' => array('driver' => 'sqlite', 'path' => '$dbfile'));
}
function kdba_tables() {
    return array('customers' => array(
        'conn' => 'main', 'table' => 'customers', 'label' => '顧客', 'pk' => 'id',
        'columns' => array('id','name','email','phone','note','created_at'),
        'search' => array('name','email','phone'),
        'editable' => array('name','email','phone','note'),
        'can_insert' => true, 'can_update' => true, 'can_delete' => false,
        'order' => 'id DESC', 'limit' => 100,
    ));
}
PHP;
file_put_contents($tmp . '/kdbagent_config.php', $conf);

/* require するために、設定を core と同じ場所から読ませる:
   core は __DIR__/kdbagent_config.php を読むので、core を一時ディレクトリへ複製する */
$core_tmp = $tmp . '/kdbagent.php';
copy($core, $core_tmp);
require $core_tmp;   // $KDBA_IS_MAIN が false になり、関数だけ入る

echo "\n[1] 参照・検索\n";
$rows = kdba_select('customers', array());
ok(count($rows) === 3, '全件取れる', count($rows));
ok(!array_key_exists('secret', $rows[0]), '宣言していない secret 列は出てこない', array_keys($rows[0]));
$hit = kdba_select('customers', array('search' => 'suzuki'));
ok(count($hit) === 1 && $hit[0]['name'] === '鈴木花子', 'メールで検索できる');
$one = kdba_row('customers', 1);
ok($one && $one['email'] === 'tanaka@example.jp', 'idで1件取れる');

echo "\n[2] 宣言の外は触れない\n";
try { kdba_select('secrets', array()); ok(false, '宣言外の表 secrets は拒否'); }
catch (KdbaError $e) { ok(true, '宣言外の表 secrets は拒否'); }
try { kdba_select('customers', array('where' => array('secret' => 'x'))); ok(false, '宣言外の列でのwhereは拒否'); }
catch (KdbaError $e) { ok(true, '宣言外の列でのwhereは拒否'); }
// editable にない列は更新時に捨てられる（secret や created_at は書けない）
$before = kdba_row('customers', 1);
kdba_update('customers', 1, array('secret' => '書き換え', 'created_at' => '1999-01-01', 'name' => '田中改'));
$after = kdba_row('customers', 1);
ok($after['name'] === '田中改', 'editableの name は更新される');
ok($after['created_at'] === $before['created_at'], 'editable外の created_at は変わらない', $after['created_at']);
$raw = (new PDO('sqlite:' . $dbfile))->query("SELECT secret FROM customers WHERE id=1")->fetchColumn();
ok($raw === 'TOP-SECRET', 'editable外の secret も変わらない（実DBで確認）', $raw);

echo "\n[3] 操作の可否\n";
try { kdba_delete('customers', 2); ok(false, 'can_delete=false の削除は拒否'); }
catch (KdbaError $e) { ok(true, 'can_delete=false の削除は拒否'); }
$ins = kdba_insert('customers', array('name' => '新規太郎', 'email' => 'new@example.jp'));
ok(isset($ins['inserted_id']), '追加できる（can_insert=true）');
ok(count(kdba_select('customers', array())) === 4, '追加後は4件');

echo "\n[4] SQLインジェクションが素通りしない\n";
// 値としての "'; DROP TABLE" は文字列として保存され、表は生きている
$evil = kdba_select('customers', array('search' => "DROP TABLE"));
ok(count($evil) === 1, '危険な文字列は「ただの検索語」として扱われる', count($evil));
ok(count(kdba_select('customers', array())) === 4, 'customers 表は破壊されていない');

echo "\n[5] スキーマの出力（エージェントが最初に読む）\n";
$sch = kdba_schema();
ok(count($sch) === 1 && $sch[0]['key'] === 'customers', 'schema一覧が出る');
ok($sch[0]['can_delete'] === false, '削除不可が伝わる');
ok(!in_array('secret', $sch[0]['columns'], true), 'schemaにも宣言外の列は出ない');

echo "\n[6] 監査ログ\n";
$log = file_get_contents($tmp . '/audit.log');
ok(strpos($log, '"action":"insert"') !== false, '追加が記録される');
ok(strpos($log, '"action":"update"') !== false, '更新が記録される');

echo "\n[7] CLI入口（サブプロセス・実際の使われ方）\n";
function cli($tmp, $args) {
    $cmd = 'php ' . escapeshellarg($tmp . '/kdbagent.php') . ' ' . $args . ' 2>/dev/null';
    return json_decode(shell_exec($cmd), true);
}
$r = cli($tmp, 'tables');
ok(isset($r['ok']) && $r['ok'] && count($r['schema']) === 1, 'php kdbagent.php tables がJSONを返す');
$r = cli($tmp, 'select customers --search 田中');
ok($r['ok'] && $r['count'] >= 1, 'select --search が効く', $r);
$r = cli($tmp, 'insert customers --set name=CLI太郎 --set email=cli@example.jp');
ok($r['ok'] && isset($r['inserted_id']), 'insert できる');
$r = cli($tmp, 'select secrets');
ok(isset($r['ok']) && $r['ok'] === false, '宣言外の表はCLIでも拒否', $r);
$r = cli($tmp, 'delete customers --id 2');
ok(isset($r['ok']) && $r['ok'] === false, 'can_delete=false はCLIでも拒否');

/* 後片付け */
array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo "\n";
echo ($fail === 0 ? "すべて通りました（{$pass}件）\n" : "失敗 {$fail}件 / 成功 {$pass}件\n");
exit($fail === 0 ? 0 : 1);
