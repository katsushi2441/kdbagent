#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・データ・鍵は入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kdbagent-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kdbagent.php public/kdbagent_config.php.example \
  scripts/make_password_hash.php scripts/check_kdbagent.php \
  skills docs README.md LICENSE \
  -x '*.sqlite' -x '*.log' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"
