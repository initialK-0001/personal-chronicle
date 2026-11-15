# エックスサーバー向け保存基盤

2026-11-16の実装。`xserver/` は独立したPHP APIとCLI取込処理。**開発環境で検証済み、本番未配備、現行画面との接続は未完了。** Sites版の保存先や既存WordPressは変更していない。

## 実装済み

- PDOによる投稿保存。IDはDBで自動採番し、入力されたIDや日時を採用しない。
- 投稿ごとの下書きと公開スナップショットを分離。自動保存は公開版を変更しない。公開更新でのみ反映。非公開化は本文も下書きも削除しない。再公開でも初回日時を維持。
- 改訂番号の条件付き更新とトランザクション。競合時409、失敗時ロールバック。改訂履歴は非公開テーブルに保持。
- 公開APIは許可項目だけを返し、下書き・改訂・移行原文を返さない。
- PHP標準パスワードハッシュ・サーバー側セッション。ログイン時ID更新、8時間期限、ログアウト、Secure/HttpOnly/SameSite=Strict、OriginとCSRFトークン確認。IPは生値を保存せずHMAC化し、15分窓で8回の試行制限。古い制限レコードは削除。
- 秘密設定・セッション・SQLiteは公開ルート外。外部認証ヘッダーを信用しない。設定欠落時は停止する。
- 旧記事の非公開ステージング取込。元レコード・HTML・分類・状態・日時を保持し、元HTMLのSHA-256を記録。再実行時の重複なし、内容変化は409で停止。公開状態が元からpublishでも、この取込では公開しない。

## 動作条件と配置

PHP 8.3以上、PDO、PDO SQLite（検証用）またはPDO MySQL（本番候補）。検証はPHP 8.4.27 + SQLiteで実施。MySQL/InnoDB向けDDL分岐は実装したが、実MySQLでの検証は未完了。本番ドメインのPHP 7.4は変更していない。

公開するのは `xserver/public` のみ。`src` と `bin`、設定JSON、DB、セッションは公開しない。ApacheのRewriteで公開ルートをindex.phpに渡す。`CHRONICLE_CONFIG` 環境変数で公開領域外のJSONを指定する。設定をリポジトリやHTTPレスポンスへ出さない。

設定のキーは `dsn`, `db_user`, `db_password`, `origin`, `password_hash`, `rate_secret`, `sessions`。MySQL DSNは `charset=utf8mb4` を指定。`password_hash` はPHPの `password_hash()` で生成し、平文をファイルに残さない。`rate_secret` は暗号学的乱数を使用。HTTPの `local_test=true` は127.0.0.1専用で、本番には使わない。秘密ディレクトリ0700、設定0600を原則とする。

CLIのみで `php xserver/bin/setup.php` を実行して専用DBにテーブルを作成する。既存WordPress DBへの実行は不可。DDLは取込トランザクションと分離。DBとバックアップの専用ユーザー・権限を配備時に確認する。

## API契約

| 経路 | 内容 |
| --- | --- |
| GET /api/session | 認証状態とCSRFトークン |
| POST /api/login | パスワード認証 新しいCSRFトークンを返す |
| POST /api/logout | セッションの認証状態を破棄 |
| POST /api/admin/posts | 下書きを新規作成 サーバー採番 |
| GET /api/admin/posts/:id | 管理者だけが投稿の下書きと確定内容を取得 |
| POST /api/admin/posts/:id | revisionとaction（draft/release/unpublish）を指定 contentは必要時のみ |
| GET /api/public/posts | 公開済みスナップショットのみ |

変更リクエストはJSON、同一Origin、`X-CSRF-Token` が必要。管理APIはセッション認証を要求。Sites版の `/api/state` とは異なる契約であり、画面をそのまま接続できると扱わない。

## 旧記事取込

```sh
python3 scripts/export-wordpress-staging.py /private/wp-staging.sqlite --prefix VERIFIED_PREFIX --output /private/posts-import.json
php xserver/bin/import.php /private/posts-import.json
```

実データ388件を独立した検証SQLiteへ取り込み、原文・元レコード・分類・日時・状態の全件一致を確認。2回目は追加0件・一致388件。公開レコードは0件のまま。元のSQL/画像バックアップは別途保持。

## 再現可能な検証

```sh
php xserver/tests/store.php
PHP_BIN=php python3 xserver/tests/http_integration.py
python3 -m unittest discover -s tests -p 'test_*.py'
```

HTTP試験は一時ディレクトリ・乱数のテスト認証情報・ローカルPHPサーバーを使用し、実データや本番認証には接続しない。検証後に終了・削除する。

## 本番切替前に残る作業

1. 管理画面接続、画像アップロード・再エンコード・非公開配信制御。現APIは文章・見出し・引用・リンクまでで、画像ブロックを未対応として拒否する。
2. 旧HTMLの安全な表示・ショートコード・添付リンクと既存URLの解決。ステージング原文をそのまま公開しない。
3. 一般コメント、広告、固定ページ、検索・SEO、一覧のページ分割。
4. 実ホストPHP/DB/セッション・HTTPSでの検証、管理者認証設定、追加認証と復旧経路の確認。自作のアプリ認証層は本番前に再レビューする。
5. 独立した全ファイルバックアップと復元試験、具体的な切替・戻し手順、利用者による切替承認。

PHP標準機能を使った小規模な実装として検証を始めた段階であり、設計時に候補としたLaravel等の認証基盤を採用済みとはしない。

参考（2026-11-16確認）：PHP公式の[セッション管理](https://www.php.net/manual/ja/features.session.security.management.php)、[PDOトランザクション](https://www.php.net/manual/ja/pdo.transactions.php)。
