# エックスサーバー向け保存基盤

2026-11-16の実装。`xserver/` は独立したPHP APIとCLI取込処理。**開発環境で検証済み、本番未配備、投稿画面との接続済み ブラウザー通し操作は未検証。** Sites版の保存先や既存WordPressは変更していない。

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

変更リクエストはJSON、同一Origin、`X-CSRF-Token` が必要。管理APIはセッション認証を要求。`/api/state` の互換経路を追加し PHP専用画面へ接続済み 投稿ごとの改訂番号で競合を検出する。

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

1. 実ブラウザーでの投稿から再編集までの通し確認 画像のEXIF回転補正 大容量時のメモリー確認。
2. 旧記事全件の表示差分確認 Amazonアフィリエイト識別子の維持 旧記事の編集 お問い合わせフォーム対応。
3. 一般コメント、広告、固定ページ、検索・SEO、一覧のページ分割。
4. 実ホストPHP/DB/セッション・HTTPSでの検証、管理者認証設定、追加認証と復旧経路の確認。自作のアプリ認証層は本番前に再レビューする。
5. 独立した全ファイルバックアップと復元試験、具体的な切替・戻し手順、利用者による切替承認。

PHP標準機能を使った小規模な実装として検証を始めた段階であり、設計時に候補としたLaravel等の認証基盤を採用済みとはしない。

参考（2026-11-16確認）：PHP公式の[セッション管理](https://www.php.net/manual/ja/features.session.security.management.php)、[PDOトランザクション](https://www.php.net/manual/ja/pdo.transactions.php)。

## 追加実装 2026-11-16

- `scripts/build-xserver-ui.py` が承認済みUIからPHP用画面を生成する Sitesの試作画面は変更しない。ログイン・新規投稿・複数下書き・保存・公開更新・非公開化・ログアウトをPHPへ接続。試作用サンプルは含めない。
- JPEG/PNG/WebPは8MiB・1600万画素まで GDでPNG再生成しメタデータを除去。画像は `images` で指定する公開領域外へ保存。公開スナップショットが参照する画像だけ匿名配信。EXIFの向き補正は未実装。
- 旧記事表示は `legacy_public_enabled` が厳密にtrueの場合だけ有効 初期値false。元から公開されパスワードのない記事・固定ページのみ 元URLでサーバー側HTML表示。下書きと非公開記事は対象外。HTMLPurifierでスクリプトと危険な属性を除去。
- blogcardはリンク amazonjsは商品リンク wpdm_packageは配布リンクへ変換。Amazonの広告識別子はまだ復元していない。contact-form-7は準備中の表示。未対応埋め込みを完全互換とは扱わない。
- `legacy_uploads` はバックアップから分離した旧uploadsを指定し 公開記事に参照のある画像と公開設定済みの単一配布ファイルだけ返す。public配下に旧uploadsを直接置かない。管理者の旧記事プレビューは本文のみ対応し 非公開段階の旧画像プレビューは未対応。
- `php xserver/tests/legacy.php` で公開状態・パスワード記事除外・HTML無害化・短縮コード変換を確認。HTTPテストは画像の公開前404・公開後200・非公開後404まで確認。PHP8.4で実施し MySQLは未検証。
- 依存導入は xserver 内で `composer install --no-dev` ロックファイルを使用。2026-11-16のComposer監査で既知の脆弱性情報なし。

## 配備パッケージ

`python3 scripts/package-xserver.py /private/chronicle-code.zip` で依存を含むコードのみ梱包。設定・DB・移行JSONは含めない。`php xserver/bin/preflight.php` は書込みや取込みをせず環境を確認する。

配備時は本体のsrc/ui/vendor/binを公開領域外に置き public/index.phpの参照先をその配置に合わせる。現在の相対配置は public をドキュメントルートにできる独立環境向けであり 既存public_htmlへそのまま全体を展開してはいけない。本番配置パスを確定してから入口ファイルを生成する。旧WordPressの停止・PHP変更・切替は未実施。
