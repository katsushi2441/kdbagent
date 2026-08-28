<?php
/**
 * Whop で Kurage DB Agent が購入されたら、ダウンロード用のリンクをメールで送る。
 *
 * なぜ必要か（2026-08-28）:
 *   Whop CLI には商品へ配布ファイルを添付するコマンドが無く、管理画面での投入は
 *   過去に詰まっている（iframe内のアプリで、クリックがオーバーレイに横取りされる）。
 *   購入は Whop、配布は自社側、という分担にすればCLIだけで完結する。
 *
 * 署名検証は kgeo_whop_hook.php と同じ Standard Webhooks 方式（実績のある実装を踏襲）。
 *
 * 設置:
 *   1. このファイルと kdba_whop_config.php をPHPが動くサーバーに置く
 *   2. kdba_whop_config.php に鍵とメール設定を書く（下の定数）
 *   3. Whop側にWebhookを登録:
 *        whop webhooks create --url https://…/kdba_whop_hook.php --events payment.succeeded
 *   4. 配布物 kdbagent-en-YYYYMMDD.zip を KDBA_DL_DIR に置く
 */

require_once __DIR__ . '/kdba_whop_config.php';
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=utf-8');

function kdba_out($status, $message)
{
    http_response_code($status);
    echo json_encode(array('ok' => $status < 400, 'message' => $message));
    exit;
}

function kdba_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : '';
}

/** Standard Webhooks: HMAC-SHA256("{id}.{timestamp}.{body}") をbase64。鍵の与え方が実装で分かれるため両方試す。 */
function kdba_signature_ok($body)
{
    $secret = KDBA_WHOP_WEBHOOK_SECRET;
    if ($secret === '') { return false; }
    $id = kdba_header('webhook-id');
    $ts = kdba_header('webhook-timestamp');
    $sig = kdba_header('webhook-signature');
    if ($id === '' || $ts === '' || $sig === '') { return false; }
    if (abs(time() - (int)$ts) > 300) { return false; }

    $signed = $id . '.' . $ts . '.' . $body;
    $keys = array($secret);
    if (preg_match('/^(ws_|whsec_)(.+)$/', $secret, $m)) {
        $keys[] = $m[2];
        $decoded = base64_decode($m[2], true);
        if ($decoded !== false && $decoded !== '') { $keys[] = $decoded; }
    }
    $expected = array();
    foreach ($keys as $k) { $expected[] = base64_encode(hash_hmac('sha256', $signed, $k, true)); }
    foreach (preg_split('/\s+/', trim($sig)) as $part) {
        $value = (strpos($part, ',') !== false) ? substr($part, strpos($part, ',') + 1) : $part;
        foreach ($expected as $c) { if (hash_equals($c, $value)) { return true; } }
    }
    return false;
}

/** 購入1件につき1つ、推測できないダウンロードトークンを発行して記録する。 */
function kdba_issue_token($email, $payment_id)
{
    $store = KDBA_DL_DIR . '/tokens.json';
    $all = is_file($store) ? json_decode(file_get_contents($store), true) : array();
    if (!is_array($all)) { $all = array(); }
    foreach ($all as $t => $rec) {              // 同じ決済の再送で二重発行しない
        if (isset($rec['payment_id']) && $rec['payment_id'] === $payment_id) { return $t; }
    }
    $token = bin2hex(random_bytes(24));
    $all[$token] = array(
        'email' => $email, 'payment_id' => $payment_id,
        'issued_at' => date('c'), 'downloads' => 0,
    );
    file_put_contents($store, json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($store, 0600);
    return $token;
}

function kdba_send_mail($to, $token)
{
    $url = KDBA_DL_BASE . '?t=' . $token;
    $subject = 'Your download — Kurage DB Agent';
    $body = "Thank you for your purchase.\n\n"
        . "Download (this link is yours alone; please do not share it):\n$url\n\n"
        . "What is inside\n"
        . "  kdbagent.php              the tool (browser UI + CLI + HTTP API)\n"
        . "  kdbagent_mcp.php          MCP server for an agent on the same machine\n"
        . "  kdbagent_mcp_remote.php   bridge for a server you already run\n"
        . "  docs/                     setup, security notes, remote MCP guide\n"
        . "  skills/kdbagent/SKILL.md  drop into a Claude Code project\n"
        . "  README-en.md              start here\n\n"
        . "English messages\n"
        . "  Add define('KDBA_LANG', 'en'); to your config, and pass\n"
        . "  KDBA_MCP_LANG=en when you register the MCP server.\n\n"
        . "Licence: MIT. Modify it, resell it, ship it inside your own product.\n\n"
        . "Questions for the next three months: reply to this email.\n\n"
        . "Exbridge, Inc. (Nagoya, Japan)\n"
        . "https://exbridge.jp/\n";
    $headers = 'From: ' . KDBA_MAIL_FROM . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') { kdba_out(400, 'empty body'); }
if (!kdba_signature_ok($raw)) { kdba_out(401, 'invalid signature'); }

$event = json_decode($raw, true);
if (!is_array($event)) { kdba_out(400, 'invalid json'); }
$type = isset($event['type']) ? (string)$event['type'] : '';
$data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();

// 配布は決済成立のときだけ。membership.activated は同じ購入で二重に飛ぶことがある。
if ($type !== 'payment.succeeded') { kdba_out(200, 'ignored: ' . $type); }

// この商品の決済だけを扱う（他商品のWebhookが同じURLに来ても無視する）
$plan_id = '';
if (isset($data['plan']['id'])) { $plan_id = (string)$data['plan']['id']; }
elseif (isset($data['plan_id'])) { $plan_id = (string)$data['plan_id']; }
if (KDBA_WHOP_PLAN_ID !== '' && $plan_id !== KDBA_WHOP_PLAN_ID) {
    kdba_out(200, 'ignored: other plan ' . $plan_id);
}

$email = '';
foreach (array(array('user','email'), array('member','email'), array('email')) as $path) {
    $v = $data;
    foreach ($path as $k) { $v = isset($v[$k]) ? $v[$k] : null; if ($v === null) break; }
    if (is_string($v) && strpos($v, '@') !== false) { $email = $v; break; }
}
if ($email === '') { kdba_out(200, 'no email in event'); }

$payment_id = isset($data['id']) ? (string)$data['id'] : ('nopid-' . md5($raw));
$token = kdba_issue_token($email, $payment_id);
$sent = kdba_send_mail($email, $token);

$log = KDBA_DL_DIR . '/hook.log';
@file_put_contents($log, date('c') . "\t$payment_id\t$email\tsent=" . ($sent ? 1 : 0) . "\n", FILE_APPEND | LOCK_EX);

kdba_out(200, $sent ? 'delivered' : 'token issued, mail failed');
