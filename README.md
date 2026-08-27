# Kurage DB Agent（kdbagent）

**1つのPHPファイルで、データベースの中身を安全に参照・検索・編集する。**
mysqladmin や phpMyAdmin のような「なんでもできる」道具ではなく、
**設定で宣言した表・列・操作だけ**を、人にもAIエージェントにも触らせる道具です。

- 依存ライブラリなし。`kdbagent.php` を1つ置くだけ（＋設定ファイル）。
- MySQL / SQLite の両対応。
- 3つの顔が、同じ「範囲を絞った」制限を通ります:
  1. **ブラウザ** — ログイン・検索・編集の管理画面
  2. **コマンド** — `php kdbagent.php select ...`（**Claude Code などのAIが叩ける**）
  3. **HTTP API** — トークン付きのJSON API

## なぜ「範囲を絞る」のか

任意のSQLを打てるDB管理ツールは強力ですが、Webに置くと**全データを晒す
踏み台**になりかねません。Kurage DB Agent は逆の発想です。

```php
// kdbagent_config.php — 見せる表・列・操作をここで宣言する
'customers' => array(
    'columns'    => array('id','name','email','phone','note'),  // これ以外の列は見えない
    'search'     => array('name','email','phone'),
    'editable'   => array('name','email','phone','note'),        // これ以外は書き換えられない
    'can_delete' => false,                                        // 削除は禁止
),
```

宣言していない表・列・操作は、**ブラウザからもコマンドからもAPIからも
実行できません**。だから、AIエージェント（Claude Code など）に
「顧客の電話番号を直しておいて」と任せても、宣言した範囲より外は
壊せません。これがこの製品の安全性の芯です。

## 安全のしくみ

- SQLは全部 **PDOのプリペアドステートメント**。値は必ずbindします（SQLインジェクション対策）。
- 表名・列名は、SQLに使う前に**必ず設定の宣言と照合**します。利用者が
  送ってきた文字列をそのまま識別子にしません。
- 管理画面は**パスワード認証＋CSRFトークン**。HTTP APIは**トークン**必須。
- 変更はすべて**監査ログ**（だれが・いつ・どの表の・どの行を・どう変えたか）。

## 導入

1. `public/kdbagent.php` と `public/kdbagent_config.php.example` をサーバーに置く。
2. `.example` を `kdbagent_config.php` にコピーして、接続情報と「見せる表」を書く。
3. 管理画面のパスワードを作る: `php scripts/make_password_hash.php` → 出た文字列を設定に貼る。
4. ブラウザで `kdbagent.php` を開く。

詳しくは [docs/01-overview.md](docs/01-overview.md) と [docs/02-security.md](docs/02-security.md)。

## AIエージェント（Claude Code）から使う

### 方法① MCPサーバーとして繋ぐ（推奨・同梱）

`kdbagent_mcp.php` を同じフォルダに置き、Claude Code に1行で登録します。
**追加のインストールは不要**（Node.jsもComposerも要りません。PHPだけで動きます）。

```bash
claude mcp add kdbagent -- php /path/to/kdbagent_mcp.php

# 参照だけ許して、書き込みを禁止する場合
claude mcp add kdbagent -e KDBA_MCP_READONLY=1 -- php /path/to/kdbagent_mcp.php
```

Claude Desktop の場合は `claude_desktop_config.json` に:

```json
{"mcpServers":{"kdbagent":{"command":"php","args":["/path/to/kdbagent_mcp.php"]}}}
```

登録すると、AIから次の7つの道具が使えるようになります。

| ツール | できること |
|---|---|
| `kdb_tables` | 触れる表・列・操作の範囲を知る |
| `kdb_schema` | 表の構成（列・検索対象・編集可否）を見る |
| `kdb_select` | キーワード検索・条件絞り込みで行を探す |
| `kdb_get` | 主キーで1行取り出す |
| `kdb_insert` | 1行追加する |
| `kdb_update` | 1行更新する |
| `kdb_delete` | 1行削除する（許可した表のみ） |

**生SQLを渡す口はありません。** 範囲の判定は今までどおり `kdbagent.php` と
設定ファイルが行うので、AIが宣言外の表を触ろうとしても
`その表は許可されていません` と拒否されます（削除禁止の表への削除も同様）。
読み取り専用モードでは、書き込み系の道具はAIから見えなくなります。

### 方法①-b すでにサーバーで運用している kdbagent に繋ぐ（リモートMCP）

社内サーバーやレンタルサーバーで動かしている `kdbagent.php` を、手元のPCの
Claude Code / Codex から使う場合は `kdbagent_mcp_remote.php` を使います。
手元にDB接続情報を置かずに済みます。

```bash
claude mcp add kdbagent \
  -e KDBA_URL=https://あなたのサーバー/kdbagent/kdbagent.php \
  -e KDBA_TOKEN=（サーバーに設定したAPIトークン） \
  -- php /path/to/kdbagent_mcp_remote.php
```

手順は [docs/03-remote-mcp.md](docs/03-remote-mcp.md)（Codex CLI・Claude Desktopの設定、逆引き表つき）。

### 方法② コマンドとして使う

```bash
php kdbagent.php tables                         # 触れる表と列を知る
php kdbagent.php select customers --search 田中  # 検索
php kdbagent.php update customers --id 42 --set phone=090-9999
```

`skills/kdbagent/` をプロジェクトのスキルとして置く方法も使えます。

## ライセンス

MIT License（[LICENSE](LICENSE)）。改変・商用利用・再配布は自由です。

---
株式会社エクスブリッジ ／ [Kurage プロジェクト](https://kurage.exbridge.jp/)
