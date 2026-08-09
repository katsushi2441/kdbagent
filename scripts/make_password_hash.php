<?php
/**
 * 管理画面のパスワードのハッシュを作ります。
 *   php scripts/make_password_hash.php
 * 出た文字列を kdbagent_config.php の KDBA_PASSWORD_HASH に貼ってください。
 */
if (PHP_SAPI !== 'cli') { exit("コマンドラインで実行してください\n"); }
fwrite(STDOUT, "パスワードを入力してEnter（表示されます）: ");
$pw = trim(fgets(STDIN));
if ($pw === '') { fwrite(STDERR, "空です\n"); exit(1); }
echo "\nKDBA_PASSWORD_HASH に貼る文字列:\n" . password_hash($pw, PASSWORD_DEFAULT) . "\n";
