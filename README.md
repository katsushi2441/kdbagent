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

`skills/kdbagent/` をプロジェクトのスキルとして置くと、Claude Code が
このツール越しにDBを読み書きします。生SQLを書かず、宣言した範囲だけを
安全に操作します。

```bash
php kdbagent.php tables                         # 触れる表と列を知る
php kdbagent.php select customers --search 田中  # 検索
php kdbagent.php update customers --id 42 --set phone=090-9999
```

## ライセンス

MIT License（[LICENSE](LICENSE)）。改変・商用利用・再配布は自由です。

---
株式会社エクスブリッジ ／ [Kurage プロジェクト](https://kurage.exbridge.jp/)
