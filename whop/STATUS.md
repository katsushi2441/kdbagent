# Whop 英語版の状態（2026-08-28）

- account: Exbridge (biz_PUb0gGxDpUVwRe)
- product: prod_mBENvcyQ9Gaku / route=kurage-db-agent / **visibility=hidden（未公開）**
- plan: plan_niieVCx2TMmcr / one_time / **$79 USD** / adaptive pricing 有効
- checkout: https://whop.com/checkout/plan_niieVCx2TMmcr
- 配布zip: outputs/kdbagent-en-20260828.zip（README-en.md 同梱）

## 残っていること

1. 配布ファイルのアップロード（CLIに product へファイルを添付するコマンドが見当たらない。管理画面での投入が要る可能性）
2. 商品画像・ギャラリー画像
3. visibility を visible にする（公開）
4. アフィリエイト率の設定（global_affiliate_percentage）

## 英語化の実装

- `KDBA_LANG=en`（define または環境変数）で本体のメッセージが英語になる
- `KDBA_MCP_LANG=en` でMCPのツール説明・instructions・エラーが英語になり、子プロセスの本体にも伝播する
- 既定は日本語のままなので、既存の日本語利用者には影響しない
- 実測済み: 英語モードで "This table is not allowed: sqlite_master" / "Deleting is not allowed for this table"
