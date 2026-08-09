#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kdbagent/
# 本体(public/kdbagent.php)をコピーして上げるので、常に最新と一致する。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . /home/kojima/work/aixec/.env; set +a
remote="/web/proto_exbridge_jp/kdbagent"
cp public/kdbagent.php demo/kdbagent.php
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
up demo/kdbagent.php kdbagent.php
up demo/kdbagent_config.php kdbagent_config.php
up demo/index.php index.php
up demo/.htaccess .htaccess
up demo/kdba_data/app.sqlite kdba_data/app.sqlite
echo "published: https://proto.exbridge.jp/kdbagent/"
