<?php
/**
 * 購入者にだけ配布物を渡す。トークンは購入1件につき1つ、Webhookが発行する。
 *
 * 使い方: https://…/kdba_download.php?t=（トークン）
 * トークンが無い・不正・回数超過なら何も渡さない。
 */

require_once __DIR__ . '/kdba_whop_config.php';
date_default_timezone_set('Asia/Tokyo');

$MAX_DOWNLOADS = 10;   // 買った人が環境を変えて取り直せる程度には許す

function kdba_deny($msg)
{
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Kurage DB Agent</title>'
       . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:0 20px;line-height:1.8;color:#1d3038}'
       . 'a{color:#0a726b}</style>'
       . '<h1>Download unavailable</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p>If you purchased this product and cannot download it, reply to your purchase email and we will sort it out.</p>'
       . '<p><a href="https://exbridge.jp/">Exbridge, Inc.</a></p>';
    exit;
}

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { kdba_deny('This link is not valid.'); }

$store = KDBA_DL_DIR . '/tokens.json';
if (!is_file($store)) { kdba_deny('This link is not valid.'); }

$fp = fopen($store, 'r+');
if (!$fp) { kdba_deny('Temporarily unavailable. Please try again shortly.'); }
flock($fp, LOCK_EX);
$all = json_decode(stream_get_contents($fp), true);
if (!is_array($all) || !isset($all[$token])) {
    flock($fp, LOCK_UN); fclose($fp);
    kdba_deny('This link is not valid.');
}
if ((int)$all[$token]['downloads'] >= $MAX_DOWNLOADS) {
    flock($fp, LOCK_UN); fclose($fp);
    kdba_deny('This link has reached its download limit.');
}
$all[$token]['downloads'] = (int)$all[$token]['downloads'] + 1;
$all[$token]['last_download'] = date('c');
ftruncate($fp, 0); rewind($fp);
fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE));
flock($fp, LOCK_UN); fclose($fp);

// 配布物は最新の1本を渡す（版を上げてもリンクは変えなくてよい）
$files = glob(KDBA_DL_DIR . '/kdbagent-en-*.zip');
if (!$files) { kdba_deny('The file is being updated. Please try again in a few minutes.'); }
rsort($files);
$path = $files[0];

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
