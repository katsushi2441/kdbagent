# Whop 英語版の状態（2026-08-28）

- account: Exbridge (biz_PUb0gGxDpUVwRe)
- product: prod_mBENvcyQ9Gaku / route=kurage-db-agent / **visibility=hidden（未公開）**
- plan: plan_niieVCx2TMmcr / one_time / **$79 USD** / adaptive pricing 有効
- checkout: https://whop.com/checkout/plan_niieVCx2TMmcr
- 配布zip: outputs/kdbagent-en-20260828.zip（README-en.md 同梱）

## 公開済み・マーケットプレイス審査通過（2026-08-28）

- product: **visibility=visible**
- checkout: https://whop.com/checkout/plan_niieVCx2TMmcr — **購入可能を実測**（$79 が現地通貨¥12,848で表示され、カード決済フォームが出る）
- **whop.com marketplace の審査を通過**（Whopからの通知・2026-08-28）。`discover/search?q=kurage` で
  Exbridge が3件中2位に表示されることを実測。**画像なしでも掲載された**
- ただし「database agent」(28件)「mcp」(29件)の検索では上位に出ない。露出はこれからの課題

## 配布は自前で完結させた（Whop CLIに商品へファイルを添付する機能が無いため）

- `whop/kdba_whop_hook.php` … Whopの payment.succeeded を受け、購入者メールへダウンロードリンクを送る
- `whop/kdba_download.php` … トークンでのみ配布。10回まで。zipの直リンクは403
- webhook: hook_5wdOLVMMXPxnO / secret は `kdba_whop_config.php`（gitignore・heteml上のみ）
- 設置先: exbridge.jp/kdba/（zipとtokens.jsonは .htaccess で遮断）
- **実測済み**: 署名なし→401、正しい署名→200 "delivered"（メール送信成功）、
  発行トークンでzip 40,958B取得、トークン削除後は403
- **Whop CLIのwebhook作成にはAPIキーログインが必要**（OAuthでは403）。
  `whop login --api-key <KGEO_WHOP_API_KEY>` で通る（kgeoと同じキーを流用）

## 商品画像（作成済み・紐付けだけが未完）

- ローカル: `whop/product_banner.png`(1600x900) / `whop/product_square.png`(1000x1000)
- 公開URL: https://exbridge.jp/images/kdbagent-whop-banner.png / …-square.png
- Whop CDNにもアップロード済み(file_5BWx8e2uEAYQ7・upload_status=ready・URL生存)

**CLIからの紐付けは3方式とも失敗した（2026-08-28実測）**:
1. `products update --banner_image '{"id":"file_…"}'` → エラーなしで無視される(banner_image=null)
2. `products update --banner_image '{"direct_upload_id":"file_…"}'` → "The direct upload ID provided is invalid"
3. `plans update --image '{"id":"file_…"}'` → **"Attachment does not belong to this resource"**

3の文言から、`whop files create` で上げたファイルは商品/プランに属していない扱いになると分かる。
CLIに紐付け先を指定するオプションが無い（filename と visibility のみ）。**管理画面での投入が要る。**

## 残っていること

1. **商品画像を管理画面から設定**（whop.com のダッシュボード → Kurage DB Agent → 画像）
   画像がないとストア一覧に並ばない仕組みのため、これが公開の最後の1手
2. Whopストア一覧（whop.com/exbridge/）に並んでいない。既存のKurage GEOは experience(exp_) が
   紐づいており、CLIには紐付けコマンドが見当たらない。管理画面での確認が要る
3. アフィリエイト率の設定（global_affiliate_percentage）

## 英語化の実装

- `KDBA_LANG=en`（define または環境変数）で本体のメッセージが英語になる
- `KDBA_MCP_LANG=en` でMCPのツール説明・instructions・エラーが英語になり、子プロセスの本体にも伝播する
- 既定は日本語のままなので、既存の日本語利用者には影響しない
- 実測済み: 英語モードで "This table is not allowed: sqlite_master" / "Deleting is not allowed for this table"
