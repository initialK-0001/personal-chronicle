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
2. 旧記事全件の表示差分確認 AdSense実配信と広告設定の復元 旧記事の編集 お問い合わせフォーム対応。
3. 広告、固定ページの互換、検索・SEO、一覧のページ分割、切替直前の旧コメント差分確認。新規コメント受付は実装済み。
4. 実ホストPHP/DB/セッション・HTTPSでの検証、管理者認証設定、追加認証と復旧経路の確認。自作のアプリ認証層は本番前に再レビューする。
5. 独立した全ファイルバックアップと復元試験、具体的な切替・戻し手順、利用者による切替承認。

PHP標準機能を使った小規模な実装として検証を始めた段階であり、設計時に候補としたLaravel等の認証基盤を採用済みとはしない。

参考（2026-11-16確認）：PHP公式の[セッション管理](https://www.php.net/manual/ja/features.session.security.management.php)、[PDOトランザクション](https://www.php.net/manual/ja/pdo.transactions.php)。

## 追加実装 2026-11-16

- `scripts/build-xserver-ui.py` が承認済みUIからPHP用画面を生成する Sitesの試作画面は変更しない。ログイン・新規投稿・複数下書き・保存・公開更新・非公開化・ログアウトをPHPへ接続。試作用サンプルは含めない。
- JPEG/PNG/WebPは8MiB・1600万画素まで GDでPNG再生成しメタデータを除去。画像は `images` で指定する公開領域外へ保存。公開スナップショットが参照する画像だけ匿名配信。EXIFの向き補正は未実装。
- 旧記事表示は `legacy_public_enabled` が厳密にtrueの場合だけ有効 初期値false。元から公開されパスワードのない記事・固定ページのみ 元URLでサーバー側HTML表示。下書きと非公開記事は対象外。HTMLPurifierでスクリプトと危険な属性を除去。
- blogcardはリンク amazonjsは商品リンク wpdm_packageは配布リンクへ変換。Amazon広告識別子は設定 legacy_amazon_tag から付与する。contact-form-7は準備中の表示。未対応埋め込みを完全互換とは扱わない。
- `legacy_uploads` はバックアップから分離した旧uploadsを指定し 公開記事に参照のある画像と公開設定済みの単一配布ファイルだけ返す。public配下に旧uploadsを直接置かない。管理者の旧記事プレビューは本文のみ対応し 非公開段階の旧画像プレビューは未対応。
- `php xserver/tests/legacy.php` で公開状態・パスワード記事除外・HTML無害化・短縮コード変換を確認。HTTPテストは画像の公開前404・公開後200・非公開後404まで確認。PHP8.4で実施し MySQLは未検証。
- 依存導入は xserver 内で `composer install --no-dev` ロックファイルを使用。2026-11-16のComposer監査で既知の脆弱性情報なし。

## 配備パッケージ

`python3 scripts/package-xserver.py /private/chronicle-code.zip` で依存を含むコードのみ梱包。設定・DB・移行JSONは含めない。`php xserver/bin/preflight.php` は書込みや取込みをせず環境を確認する。

配備時は本体のsrc/ui/vendor/binを公開領域外に置き public/index.phpの参照先をその配置に合わせる。現在の相対配置は public をドキュメントルートにできる独立環境向けであり 既存public_htmlへそのまま全体を展開してはいけない。本番配置パスを確定してから入口ファイルを生成する。旧WordPressの停止・PHP変更・切替は未実施。

## 2026-11-16 追加検証と配備入口

- 公開192件の変換を監査し 画像868/868 埋め込み35/35 Amazon短縮コード198/198 配布リンク36/36を確認。`php xserver/bin/audit-legacy.php /private/posts-import-v2.json` で再現。画像ファイルの取得や全ページの目視を保証する件数ではない。
- 地図はHTTPSのGoogle Maps embed 動画はYouTube embedだけを許可し 他ホストのiframeと任意scriptは除去。元のYouTube埋込2件はプロトコル省略・壊れた属性を表示時に正規化。Yahoo地図APIのscript2件は未移植で移行前に代替表示の確認が必要。
- DB原本の対象テーブル wp319d61options を限定して広告設定を確認。AmazonJSの識別子とCocoon側の識別子は別だったため 一律置換しない。amazonjs変換用の legacy_amazon_tag にはAmazonJS側の確認値を配備時に設定する。広告ID/API秘密値をソースへ書き込まない。AdSense配信は未実装。
- ブラウザーで確認したのは匿名公開画面の短文全文・長文概要・記事ページ遷移。管理画面のブラウザー操作は未確認で 認証・投稿・再編集・公開・非公開・端末間共有は実HTTPテストで検証した。
- UI用 /api/state による下書き再保存 別セッションからの復元 古い版の409拒否 公開 非公開を確認。投稿ボタンの連打で新規下書きが重複する処理を抑制。セッション保管期限を認証期限8時間に合わせた。ブラウザー終了後のログイン保持は実装していない。
- 本体を公開領域外へ配置後 `php xserver/bin/front-controller.php /private/config.json /absolute/public_html > /private/index.generated.php` で本番用入口を生成できる。生成物だけをpublic_html/index.phpに配置する（本番への配置は承認後）。Rewrite設定はpublic/.htaccessを使う。既存WordPressファイルと設定は退避・復旧手順を確定するまで変更しない。

切替を止めている項目：管理画面のブラウザー確認 実ホストMySQL/HTTPS検証 AdSenseと旧地図の互換 旧記事編集 バックアップ復元と切替戻し手順。第2段階の展示機能は切替条件にしない。

## 2026-11-16 PHPコメント受付

実装済み：公開記事への匿名コメント送信 任意の名前 承認後のみ公開 承認・削除の管理画面 承認済みコメントへの管理者返信。公開記事は新規投稿と公開済み旧記事の両方。短文投稿も一覧のコメントリンクから個別ページへ進める。評価数やコメント数を表示しない。

- コメントIDはDB自動採番。利用者が送ったIDや公開状態は採用しない。メールアドレス・サイトURLは入力欄も保存列も持たない。未承認への返信はサーバーで409拒否。
- 公開側のGETは承認済みの許可項目だけを返す。対象記事の非公開化後はコメントも404。管理APIと管理JavaScriptは管理者認証必須。管理画面は `/admin/comments`。
- POSTは同一OriginとCSRFトークン必須。入力の型・UTF-8・長さを検証。画面はtextContentで表示しHTMLを実行しない。15分区間で同一接続元から5件まで。接続元は秘密値によるHMACに変換して期限付き制限テーブルに保存し 生のIPをコメントに保存しない。隠し入力欄による単純な自動投稿の除外も実装。分散送信への完全対策ではない。
- 承認・返信・削除は改訂番号を条件とするトランザクション。古い状態の操作は409。削除はDB上の状態変更で公開・管理一覧から除外し 原文は保持する。却下・スパム区分・非公開相手への返信・メール通知はない。
- `php xserver/bin/setup.php` を既存の新サイト専用DBに実行するとコメント用の2テーブルを追加する。WordPress DBに実行しない。旧WordPressコメントはまだ移していない。

検証：PHP8.4/SQLiteで `php xserver/tests/comments.php` と `PHP_BIN=php python3 xserver/tests/http_integration.py` が成功。ブラウザーでは匿名送信→受付メッセージ→未承認のDBと公開APIの分離→承認と返信後の表示を確認。承認操作は検証用CLIから同じ処理を呼び出した。管理画面のボタン操作とMySQLでの検証は未実施。

## 2026-11-16 バックアップと復元

`xserver/bin/backup.php` と `restore.php` を追加。CLI専用で公開APIから実行できない。公開版だけの画面書出とは別に 投稿の公開版・下書き・公開状態・ID・日時 改訂履歴 旧記事原文 配布設定 画像台帳 コメントと承認状態・返信をまとめて保存する。新画像は台帳の全件 旧uploadsは公開・非公開を問わず全ファイルを含める。シンボリックリンクは拒否する。

```sh
# CHRONICLE_CONFIG は既存の非公開設定JSONを指定しておく
php xserver/bin/backup.php /private/backups/new-snapshot
# 別の空のDBを準備し 別設定JSONへ切り替える
# その設定で images と legacy_uploads は空の非公開ディレクトリとし offline_restore=true を指定
php xserver/bin/setup.php
php xserver/bin/restore.php /private/backups/new-snapshot
```

保存物は `manifest.json` `database.json` `images/` `legacy/`。ファイルごとにSHA-256を記録する。DBは読み取りトランザクションで取得 MySQLではREPEATABLE READを指定。旧uploadsは取込後に変更しない運用を前提とし 変更する保守作業とバックアップを同時に行わない。保存先は新規ディレクトリのみで既存の同名バックアップを上書きしない。作業中は `.partial-乱数` とし 全件成功後に確定名へ変更する。失敗したpartialは成功バックアップではない。

復元前に全ファイルの存在・ハッシュ・パスとDB列を検証する。復元先のDB・画像ディレクトリは空でなければ拒否。DBの復元はトランザクションとし 失敗時にロールバック。画像の途中失敗では途中ファイルを勝手に削除せず保持するので 再試行には新しい空の復元先を使う。成功時は元IDを維持し 続く投稿の自動採番も継続する。

`offline_restore=true` の設定ではHTTPリクエストを503にして復元中の表示・書込みを止める。これは空の独立した復旧環境向けであり 現行DBを初期化する機能ではない。復元後にCLIで検証してから 切替承認時に復旧環境の公開設定を変更する。

**含まれないもの**：設定JSON・パスワードハッシュ・秘密鍵・ログインセッション・一時的な送信制限・ソースコード・WordPress本体とそのDB。認証設定は別途安全に再設定 ソースはGitHubから復旧する。この新サイト用処理を旧WordPress全体の復元試験完了とは扱わない。暗号化や外部への定期転送は未実装のため バックアップは必ず非公開の保存先で保管する。

検証：PHP8.4/SQLiteの独立DBへ復元し 全対象テーブルの全列 公開スナップショット 下書き 改訂履歴 コメント 新画像ハッシュ 旧配布ファイルのバイト一致を確認。既存復元先拒否 ハッシュ不一致拒否 パス逸脱拒否 バックアップ対象内への保存拒否 復元停止設定によるHTTP503を確認。MySQLと実ホストの復元は未検証。

## 旧コメントの確認結果

保存済みの2026-11-15 DBバックアップ（SHA-256 42175f697beb90f0f555a957c3a9bdcfb9789a23b340894f195dc5209608073c）を再照合。対象の wp319d61comments は0件 元SQLにも当該テーブルのINSERT文は0本。その時点の移行対象コメントはないため 新たな旧コメント取込機能は作成していない。切替直前の最新DBでも差分を確認する。他のWordPress用テーブル接頭辞のコメントを混ぜない。

## 2026-11-16 URLと検索エンジン向け表示

- 新投稿の個別ページとトップ一覧はサーバー側で本文HTMLを返す。JavaScript実行前にも公開スナップショットだけを表示し 下書きや非公開本文を含めない。短文140文字と141文字の境界 画像なし記事で代替画像を作らないことを試験。
- 新投稿の非公開・存在しないIDは404。存在しない一般URLも401ではなく404。管理経路の認証は従来通り。旧記事・固定ページは元スラッグで表示し 末尾スラッシュとURLエンコード差異を照合する。
- 公開旧記事からカテゴリーを作り `/category/スラッグ` を維持。現在のDBカテゴリーはすべて階層なしと確認。公開記事がないカテゴリーや非公開記事だけの分類は公開しない。UI上では同名テーマに対応させる。
- `public_indexing` は未指定/falseで検索対象外。HTMLはX-Robots-Tagでnoindexとし robots.txtは全面Disallow サイトマップは404。本番切替時のみtrueにする。trueでも管理・ログインはnoindexを維持する。
- true時は `/sitemap.xml` にトップ 新規公開投稿 旧公開記事・固定ページ 公開カテゴリーを出力。公開HTMLにcanonicalを付与。非公開化した新投稿はサイトマップからも除外。外部の検索エンジンによる実クロールは未確認。
- `php xserver/bin/audit-public-routes.php /private/posts-import-v2.json docs/public-url-inventory.json` で既存公開台帳207件を照合。トップ1 記事・固定ページ192 カテゴリー14に一致し 未解決0。これはコード上の解決試験で 実ホストのHTTP取得試験ではない。
- テスト `php xserver/tests/web.php` とHTTP試験でSSR本文 エスケープ 日本時間 正規URL サイトマップのXML 公開/非公開404 開発noindex 本番設定時の公開indexと管理noindexを確認。

現在の切替条件と順序は [CUTOVER.md](CUTOVER.md)。本番設定変更はまだ行っていない。
