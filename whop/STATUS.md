# Whop 英語版の状態（2026-08-28）

- account: Exbridge (biz_PUb0gGxDpUVwRe)
- product: prod_mBENvcyQ9Gaku / route=kurage-db-agent / **visibility=hidden（未公開）**
- plan: plan_niieVCx2TMmcr / one_time / **$79 USD** / adaptive pricing 有効
- checkout: https://whop.com/checkout/plan_niieVCx2TMmcr
- 配布zip: outputs/kdbagent-en-20260828.zip（README-en.md 同梱）

## 公開済み（2026-08-28）

- product: **visibility=visible**
- checkout: https://whop.com/checkout/plan_niieVCx2TMmcr — **購入可能を実測**（$79 が現地通貨¥12,848で表示され、カード決済フォームが出る）

## 配布は自前で完結させた（Whop CLIに商品へファイルを添付する機能が無いため）

- `whop/kdba_whop_hook.php` … Whopの payment.succeeded を受け、購入者メールへダウンロードリンクを送る
- `whop/kdba_download.php` … トークンでのみ配布。10回まで。zipの直リンクは403
- webhook: hook_5wdOLVMMXPxnO / secret は `kdba_whop_config.php`（gitignore・heteml上のみ）
- 設置先: exbridge.jp/kdba/（zipとtokens.jsonは .htaccess で遮断）
- **実測済み**: 署名なし→401、正しい署名→200 "delivered"（メール送信成功）、
  発行トークンでzip 40,958B取得、トークン削除後は403
- **Whop CLIのwebhook作成にはAPIキーログインが必要**（OAuthでは403）。
  `whop login --api-key <KGEO_WHOP_API_KEY>` で通る（kgeoと同じキーを流用）

## 残っていること

1. 商品画像・ギャラリー画像（未設定）
2. Whopストア一覧（whop.com/exbridge/）に並んでいない。既存のKurage GEOは experience(exp_) が
   紐づいており、CLIには紐付けコマンドが見当たらない。管理画面での確認が要る
3. アフィリエイト率の設定（global_affiliate_percentage）

## 英語化の実装

- `KDBA_LANG=en`（define または環境変数）で本体のメッセージが英語になる
- `KDBA_MCP_LANG=en` でMCPのツール説明・instructions・エラーが英語になり、子プロセスの本体にも伝播する
- 既定は日本語のままなので、既存の日本語利用者には影響しない
- 実測済み: 英語モードで "This table is not allowed: sqlite_master" / "Deleting is not allowed for this table"
