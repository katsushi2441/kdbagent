---
name: kdbagent
description: このプロジェクトのデータベースを、Kurage DB Agent 経由で参照・検索・編集する。DBのレコードを見たい・直したい・件数を数えたい・特定の行を更新/追加したいとき。生SQLを書く前にこれを使う。設定で宣言された表・列だけを、安全に操作できる。
---

# Kurage DB Agent の使い方（エージェント向け）

このプロジェクトには `kdbagent.php` が置いてある。データベースの中身を
見たり直したりするときは、生SQLや mysql クライアントを叩く前に、まず
これを使う。**設定で宣言された表・列・操作しか通らない**ので、事故を
起こしにくい。

配置場所を確認する（見つからなければこのスキルは使えない）:

```bash
find . -name kdbagent.php -maxdepth 4 2>/dev/null
```

すべてのコマンドは JSON を返す。`{"ok":true,...}` か `{"ok":false,"error":"..."}`。

## まず、何が触れるかを知る

いきなり表名を推測しない。触れる表・列・操作は設定で決まっているので、
最初に schema を読む。

```bash
php kdbagent.php tables
```

返り値の各表に `columns`（見える列）, `search`（検索できる列）,
`editable`（書き換えられる列）, `pk`（主キー）, `can_insert/update/delete`
がある。**ここに無い表・列・操作は実行できない**（試しても
`{"ok":false}` が返る）。それが仕様。回避しようとせず、必要なら人間に
設定の追加を頼む。

## 参照・検索

```bash
# 検索語で（search列を横断してLIKE検索）
php kdbagent.php select customers --search 田中

# 列の完全一致で絞る（列は columns にあるものだけ）
php kdbagent.php select orders --where status=unpaid --limit 20

# 主キーで1行
php kdbagent.php get customers --id 42
```

## 追加・更新・削除

`--set 列=値` を必要なだけ並べる。列は `editable` にあるものだけ通る
（pk や作成日時など宣言外の列を指定しても、黙って捨てられる）。

```bash
php kdbagent.php insert customers --set name=山田 --set email=y@example.jp
php kdbagent.php update customers --id 42 --set phone=090-9999
php kdbagent.php delete customers --id 42     # can_delete=true の表のみ
```

## 使うときの約束

- **schema を最初に読む。** 表名・列名を推測して総当たりしない。
- **`{"ok":false}` は仕様。** 「その表は許可されていません」等が返ったら、
  それは設定で意図的に閉じている。バグではないので回避策を探さない。
- **書き換え・削除は、まず select で対象を確認してから。** 特に update /
  delete は、先に同じ条件で select して、意図した行だけが対象か確かめる。
- 変更はすべて監査ログに残る（だれが・いつ・どの行を・どう変えたか）。
- 値に空白や記号が含まれるときは、シェルのクォートに注意
  （`--set "note=ご要望: 至急"`）。

## しないこと

- 生SQLを書いてDBを直接叩く（このツールを飛ばす意味がない。範囲制限も
  監査も外れる）。
- 設定ファイル `kdbagent_config.php` を勝手に書き換えて表や列を増やす
  （見せる範囲は人間が決める。増やしたいときは提案して承認をもらう）。
