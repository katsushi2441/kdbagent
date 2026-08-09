# Kurage DB Agent — 概要と使い方

## これは何か

データベースの「決まった表の、決まった列」を、非エンジニアでも安全に
編集できるようにする1ファイルのツールです。在庫台帳・顧客名簿・予約表の
ように、**中身は時々直したいが、DB全体を触らせたくはない**データに向いて
います。

phpMyAdmin / Adminer が「DBの管理者のための万能ツール」だとすれば、
Kurage DB Agent は「特定の担当者（や、AIエージェント）のための、
範囲を絞った編集ツール」です。

## 3つの使い方

同じ `kdbagent.php` が、同じ制限を通して3通りに使えます。

### 1. ブラウザ（人が使う）

`kdbagent.php` を開くとログイン画面。パスワードを入れると、設定した表が
タブで並びます。検索・編集・追加・削除（許可した操作だけ）ができます。

### 2. コマンド（AIエージェントや自動処理が使う）

```bash
php kdbagent.php tables                          # 触れる表と列
php kdbagent.php select customers --search 田中   # 検索（JSON）
php kdbagent.php get    customers --id 42
php kdbagent.php insert customers --set name=山田 --set email=y@example.jp
php kdbagent.php update customers --id 42 --set phone=090-9999
php kdbagent.php delete customers --id 42
```

すべて JSON を返すので、Claude Code のようなAIエージェントがそのまま
読み書きできます。`skills/kdbagent/` を同梱してあります。

### 3. HTTP API（外部の別システムが使う）

設定で `KDBA_API_TOKEN` を入れると、`?api=1` のJSON APIが有効になります。

```bash
curl 'https://example.com/kdbagent.php?api=1' \
  -H 'X-KDBA-Token: あなたのトークン' \
  -d cmd=select -d table=customers -d search=田中
```

## 設定の書き方

`kdbagent_config.php`（`.example` からコピー）で、接続と「見せる表」を
宣言します。

```php
function kdba_connections() {
    return array(
        'main' => array('driver' => 'sqlite', 'path' => __DIR__ . '/kdba_data/app.sqlite'),
        // MySQL の場合:
        // 'shop' => array('driver'=>'mysql','host'=>'localhost','dbname'=>'myshop',
        //                 'user'=>'u','pass'=>'p','charset'=>'utf8mb4'),
    );
}

function kdba_tables() {
    return array(
        'customers' => array(
            'conn'       => 'main',
            'table'      => 'customers',
            'label'      => '顧客',
            'pk'         => 'id',
            'columns'    => array('id','name','email','phone','note','created_at'),
            'search'     => array('name','email','phone'),
            'editable'   => array('name','email','phone','note'),
            'can_insert' => true,
            'can_update' => true,
            'can_delete' => false,
            'order'      => 'id DESC',
            'limit'      => 100,
        ),
    );
}
```

- `columns` に無い列は、どこにも出てきません（`secret` のような列を
  隠せます）。
- `editable` に無い列は、更新フォームに出ず、コマンドで指定しても捨てられ
  ます（`created_at` や `id` を守れます）。
- `can_delete => false` の表は、削除ボタンもコマンドも通りません。

## 動作環境

- PHP 7.0 以降、PDO の sqlite / mysql ドライバ。
- 一般的なレンタルサーバー（さくら・ロリポップ・heteml 等）でそのまま
  動きます。
