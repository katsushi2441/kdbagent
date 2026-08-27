# サーバーに置いた kdbagent を Claude Code / Codex から使う（リモートMCP）

すでにサーバーで運用している `kdbagent.php` を、手元のPCの Claude Code や
Codex CLI から使えるようにする手順です。手元にDB接続情報を置く必要はありません。

同梱の `kdbagent_mcp_remote.php` が、サーバーのHTTP APIとAIエージェントの
橋渡しをします（PHP1枚・追加インストール不要）。

## 仕組み

```
手元のPC                                 サーバー
Claude Code / Codex
   ↓ MCP（標準入出力）
kdbagent_mcp_remote.php  ──HTTPS──→  kdbagent.php（?api=1）
                                          ↓ 宣言した範囲だけ
                                        データベース
```

範囲の判定は今までどおりサーバー側の `kdbagent_config.php` が行います。
ブリッジは生SQLを通す口を持ちません。

## 1. サーバー側: APIトークンを設定する

`kdbagent_config.php` の `KDBA_API_TOKEN` に、推測されない長い文字列を入れます。
未設定のままだとHTTP APIは無効です（ブラウザ画面だけが動く状態）。

```php
define('KDBA_API_TOKEN', 'ここに64文字程度のランダム文字列');
```

トークンの作り方（どちらでも可）:

```bash
openssl rand -hex 32
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

**必ずHTTPSで公開されているURLに設置してください。** トークンはヘッダで送るため、
http:// だとネットワーク上で盗まれます（ブリッジ側もhttps以外は起動を拒否します）。

設定できたか確認:

```bash
curl -s 'https://あなたのサーバー/kdbagent/kdbagent.php?api=1' \
  -H 'X-KDBA-Token: 設定したトークン' -d cmd=tables
```

`{"ok":true,"schema":[...]}` が返れば成功です。

## 2. 手元のPC: ブリッジを置いて登録する

`kdbagent_mcp_remote.php` を任意の場所に置きます（サーバーではなく手元のPCです）。

### Claude Code

```bash
claude mcp add kdbagent \
  -e KDBA_URL=https://あなたのサーバー/kdbagent/kdbagent.php \
  -e KDBA_TOKEN=設定したトークン \
  -- php /path/to/kdbagent_mcp_remote.php

claude mcp list   # ✔ Connected と出れば成功
```

参照だけ許して書き込みを禁止する場合は `-e KDBA_MCP_READONLY=1` を足します。

### Codex CLI

```bash
codex mcp add kdbagent \
  --env KDBA_URL=https://あなたのサーバー/kdbagent/kdbagent.php \
  --env KDBA_TOKEN=設定したトークン \
  -- php /path/to/kdbagent_mcp_remote.php

codex mcp list
```

`~/.codex/config.toml` に直接書く場合:

```toml
[mcp_servers.kdbagent]
command = "php"
args = ["/path/to/kdbagent_mcp_remote.php"]

[mcp_servers.kdbagent.env]
KDBA_URL = "https://あなたのサーバー/kdbagent/kdbagent.php"
KDBA_TOKEN = "設定したトークン"
```

### Claude Desktop

```json
{"mcpServers":{"kdbagent":{"command":"php",
  "args":["/path/to/kdbagent_mcp_remote.php"],
  "env":{"KDBA_URL":"https://あなたのサーバー/kdbagent/kdbagent.php","KDBA_TOKEN":"..."}}}}
```

## 3. 使う

AIに日本語で頼めます。

- 「見積の表にどんな列がある?」
- 「コードに KUSN が入っている見積を探して」
- 「id 2306096213085 の原価を確認して」

最初に `kdb_tables` で触れる範囲を確認してから動くので、宣言していない表や
削除禁止の表に触ろうとすれば、サーバー側が拒否します。

## うまくいかないときの逆引き

| 症状 | 原因と対処 |
|---|---|
| `HTTP APIは無効です` | サーバー側の `KDBA_API_TOKEN` が空。手順1をやり直す |
| `トークンが違います`（401） | 手元の `KDBA_TOKEN` とサーバーの値が不一致。前後の空白にも注意 |
| `APIの応答がJSONではありません` | `KDBA_URL` が `kdbagent.php` を指していない（ディレクトリ止まりなど） |
| `KDBA_URL は https:// で指定してください` | http:// のURLを渡している。トークンが平文で流れるため拒否している |
| Codexで `user cancelled MCP tool call` | 非対話実行（`codex exec`）ではMCP呼び出しが承認されずキャンセルされる。対話モードの `codex` で使う |
| `サーバーに繋がりません` | URL・ファイアウォール・SSL証明書を確認 |

## 運用の注意

- **トークンは鍵と同じです。** Slackやメールに貼らず、各PCの設定に直接入れてください。漏れたら手順1で作り直せば、古いトークンは即座に無効になります。
- 参照だけで足りる相手には `KDBA_MCP_READONLY=1` を配ってください。書き込みの道具がAIから見えなくなります。
- 誰が何を変えたかは、サーバー側の監査ログ（`kdba_data/audit.log`）に残ります。API経由の変更は `api` として記録されます。
- そもそも書き換えさせたくない表は、サーバー側の設定で `can_update`/`can_delete` を `false` にしてください。設定が最終的な防波堤です。
