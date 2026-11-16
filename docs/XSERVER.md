# エックスサーバー向け保存基盤

最新の配備状況はSTATUS.mdとCUTOVER.mdを優先。実サーバーの専用MySQLへ旧記事と画像を非公開取込済み 管理認証と本番切替は未完。以下の初期記録は過去時点を含む。

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

1. 実ブラウザーでの投稿から再編集までの通し確認 実機写真のアップロードと実ホストのメモリー確認。
2. 旧記事全件の表示差分確認 AdSense実配信と広告設定の復元 旧記事編集画面の実操作 問い合わせの管理画面確認。
3. 広告、固定ページの互換、検索・SEO、一覧のページ分割、切替直前の旧コメント差分確認。新規コメント受付は実装済み。
4. 実ホストPHP/DB/セッション・HTTPSでの検証、管理者認証設定、追加認証と復旧経路の確認。自作のアプリ認証層は本番前に再レビューする。
5. 独立した全ファイルバックアップと復元試験、具体的な切替・戻し手順、利用者による切替承認。

PHP標準機能を使った小規模な実装として検証を始めた段階であり、設計時に候補としたLaravel等の認証基盤を採用済みとはしない。

参考（2026-11-16確認）：PHP公式の[セッション管理](https://www.php.net/manual/ja/features.session.security.management.php)、[PDOトランザクション](https://www.php.net/manual/ja/pdo.transactions.php)。

## 追加実装 2026-11-16

- `scripts/build-xserver-ui.py` が承認済みUIからPHP用画面を生成する Sitesの試作画面は変更しない。ログイン・新規投稿・複数下書き・保存・公開更新・非公開化・ログアウトをPHPへ接続。試作用サンプルは含めない。
- JPEG/PNG/WebPは8MiB・1600万画素まで GDでPNG再生成しメタデータを除去。画像は `images` で指定する公開領域外へ保存。公開スナップショットが参照する画像だけ匿名配信。JPEGはEXIFの全8方向を画素へ反映後 メタデータを除去。PHP拡張exif・mbstringを必須とし preflightで確認。回転用の作業領域を含む概算がPHPメモリ上限を超える画像は事前に413で拒否するため 環境によって1600万画素未満でも縮小が必要。HEICは未対応 WebPのEXIF方向情報は未対応。
- 旧記事表示は `legacy_public_enabled` が厳密にtrueの場合だけ有効 初期値false。元から公開されパスワードのない記事・固定ページのみ 元URLでサーバー側HTML表示。下書きと非公開記事は対象外。HTMLPurifierでスクリプトと危険な属性を除去。
- blogcardはリンク amazonjsは商品リンク wpdm_packageは配布リンクへ変換。Amazon広告識別子は設定 legacy_amazon_tag から付与する。contact-form-7は末尾の問い合わせ実装節で対応。未対応埋め込みを完全互換とは扱わない。
- `legacy_uploads` はバックアップから分離した旧uploadsを指定し 公開記事に参照のある画像と公開設定済みの単一配布ファイルだけ返す。public配下に旧uploadsを直接置かない。管理者の旧記事プレビューは本文のみ対応し 非公開段階の旧画像プレビューは未対応。
- `php xserver/tests/legacy.php` で公開状態・パスワード記事除外・HTML無害化・短縮コード変換を確認。HTTPテストは画像の公開前404・公開後200・非公開後404まで確認。PHP8.4で実施し MySQLは未検証。
- 依存導入は xserver 内で `composer install --no-dev` ロックファイルを使用。2026-11-16のComposer監査で既知の脆弱性情報なし。

## 配備パッケージ

`python3 scripts/package-xserver.py /private/chronicle-code.zip` で依存を含むコードのみ梱包。設定・DB・移行JSONは含めない。`php xserver/bin/preflight.php` は書込みや取込みをせず環境を確認する。

配備時は本体のsrc/ui/vendor/binを公開領域外に置き public/index.phpの参照先をその配置に合わせる。現在の相対配置は public をドキュメントルートにできる独立環境向けであり 既存public_htmlへそのまま全体を展開してはいけない。本番配置パスを確定してから入口ファイルを生成する。旧WordPressの停止・PHP変更・切替は未実施。

## 2026-11-16 追加検証と配備入口

- 公開192件の変換を監査し 画像868/868 埋め込み35/35 Amazon短縮コード198/198 配布リンク36/36を確認。`php xserver/bin/audit-legacy.php /private/posts-import-v2.json` で再現。画像ファイルの取得や全ページの目視を保証する件数ではない。
- 地図はHTTPSのGoogle Maps embed 動画はYouTube embedだけを許可し 他ホストのiframeと任意scriptは除去。元のYouTube埋込2件はプロトコル省略・壊れた属性を表示時に正規化。Yahoo地図APIのscript2件は未移植で移行前に代替表示の確認が必要。
- DB原本の対象テーブル wp319d61options を限定して広告設定を確認。AmazonJSの識別子とCocoon側の識別子は別だったため 一律置換しない。amazonjs変換用の legacy_amazon_tag にはAmazonJS側の確認値を配備時に設定する。広告ID/API秘密値をソースへ書き込まない。AdSense配信コードは後掲の2026-11-16節で追加 実配信は未有効化。
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

## 2026-11-16 旧記事の編集

- `/admin/legacy-edit` は既存記事の管理一覧 検索に対応。各記事を直接開き タイトル・本文を編集。HTMLコードの入力や別記事へのコピーを求めない。本文は移行時のHTMLを表示する編集領域とし 太字・見出し・引用・リンク・画像追加に対応。新規投稿のブロック編集とは現時点で異なる。
- 入力後にサーバーへ下書きを自動保存し 明示保存も可能。端末保存はしない。保存中・未保存時の離脱警告と 古い改訂の409拒否を設ける。競合・通信失敗時は入力を画面に残し 自動上書きを止める。ブラウザー強制終了時の未保存内容までは保証しない。
- 認証済みAPI `/api/admin/legacy` と `/api/admin/legacy/:id` で取得・変更。変更はOriginとCSRF検証。公開API・旧URL・カテゴリー・サイトマップ・コメントの公開判定は確定した版を参照する。旧記事の初回公開日時は維持し 更新時刻はサーバーで自動設定。旧下書きの初公開はその時刻を使用する。
- 新テーブル `chronicle_legacy_edits` に下書き・公開版・公開状態・改訂・日時 `chronicle_legacy_history` に変更履歴を保存。`chronicle_imports` の原文・元JSON・ハッシュ・URLは書き換えない。設定の `legacy_public_enabled` がfalseなら 編集済みの記事も匿名公開しない。旧非公開・パスワード付き記事は初期状態で非公開とする。
- HTMLは編集画面・プレビュー・公開画面の全出力で同じ許可リストを使用。編集画面には安全な表示HTMLのみを挿入。貼り付けはテキストのみ。タイトルだけの編集では元HTML・短縮コードをそのまま保存し 本文編集時は表示HTMLを保存する。変換済み配布リンクの公開判定も継続する。元の短縮コード表現は原文に残る。
- 既存画像の管理プレビューは認証付き `/admin/legacy-media/` 経由。新しく追加した画像は通常の非公開アップロードを使用し 旧記事の確定版に参照されて初めて匿名配信する。非公開化後は他の公開投稿で参照されていない画像を404に戻す。
- バックアップ形式を2へ更新して旧記事の下書き・公開版・履歴を含める。旧形式1は新テーブルを空として復元可能。配備時に `php xserver/bin/setup.php` を再実行し CREATE TABLE IF NOT EXISTSで2テーブルを追加する。既存テーブルの削除・置換はしない。
- PHP単体と実HTTPで同一URL更新 下書きの非公開 別セッション復元 競合拒否 原文不変 HTML無害化 非公開化時404 新画像公開制御 サイトマップ除外を確認。復元試験で編集2テーブルの全列一致と形式1互換を確認。実ブラウザーでの編集操作 実MySQL 全旧記事の表示比較は未確認。

## 2026-11-16 AdSenseの引継ぎ

保存済み原本DBの対象optionsだけを検査。Cocoonは手動配置 `by_myself` で 共通レスポンシブ広告枠と関連コンテンツ枠 端末別widget枠を確認。一覧上/下 サイドバー上/下 本文上/中 SNS下 関連記事下の配置フラグが有効だった。フラグは実際の配信・アカウントの自動広告有効を保証しない。Complianzの保存設定から認定CMPの稼働は確認できなかった。

`python3 scripts/audit-wordpress-ads.py /private/backup.sql.gz --prefix wp319d61 --output /private/adsense-migration.json` で識別子と配置フラグのみ抽出。任意のoptionsや秘密キーは出力しない。共通枠はformat=autoに限定し関連コンテンツ枠と区別。既存出力は上書きしない。元DBを実行しない。実識別子は配備先の非公開設定へ移し ソースに固定しない。

設定例（下記IDはテスト用で実配信不可）：
```json
"adsense": {
  "enabled": false,
  "account_settings_verified": false,
  "client": "ca-pub-0000000000000000",
  "slot": "0000000000"
}
```

`Ads.php`は公開HTML・public_indexing=true・local_testでないHTTPS環境・上記2フラグtrue・正しいID形式がそろった場合だけ広告を挿入する。管理画面 ログイン 開発プレビュー APIには挿入しない。作品や本文の中へ割り込まず ページ末尾に共通レスポンシブ枠を1つ配置し スポンサーリンク表示と最小高さを確保。旧配置数をそのまま再現したものではなく 収益の維持は未検証。自動広告はアカウント側の設定に従うため オーバーレイ等の設定は切替前に実確認する。

Google公式の対応方式に合わせ 広告を出す公開ページだけランダムnonceとstrict-dynamicを使用し 自サイトのスクリプトにもnonceを付与。広告なしの管理・開発ページは従来の制限を維持。Google広告が必要とするunsafe-eval等を含むため 本番で許可範囲と配信結果を確認する。広告の実読込・クリック・収益計測は検証環境では行わない。

公開済みの旧 `/privacy-policy` が存在する場合 公開画面の下部へリンクする。本文は原文を引き継ぐが 古いWordPress・Analytics等の記載を現運用に合わせる確認は未完了。EEA・英国・スイス向けのパーソナライズ広告に必要な認定CMP等の設定は AdSenseアカウントで確認する。`account_settings_verified` は確認記録を残した後に設定する運用フラグであり このフラグ自体が同意取得を実装するものではない。

公式確認先（2026-11-16）：
- CSP https://support.google.com/adsense/answer/16283098?hl=ja
- 同意管理 https://support.google.com/adsense/answer/13554116?hl=ja
- Google CMP https://support.google.com/adsense/answer/16918505?hl=ja

本番の配信コード有効化 PHP変更 WordPress停止はまだ行っていない。ads.txtは以前の取得で404だったため 発行者アカウント側で必要レコードを確認後に対応する。

## 2026-11-16 管理画面の引継ぎ修正

下書きボタンは `/admin?view=drafts` へ遷移し 通常の一覧描画で未公開日時の投稿を抽出する。記事詳細からでも一覧へ移れ 再読込で表示状態を維持。初回公開済みの改稿は記事の編集から開く。

フォーカス復帰時の取得は開始前だけでなく応答後も編集・アップロード・編集開始処理・編集世代・保存キューを照合する。途中で編集や保存が始まっていれば古い応答を適用しない。`tests/xserver-admin.test.mjs` で編集開始後 保存完了後の遅延応答拒否と通常待機時の更新を確認。

引継ぎのブラウザー検証記録と 今回の再実行範囲はVERIFICATION.mdの末尾を参照。追加認証を省くために管理認証を無効化する構成は使用していない。本番の変更はない。

## 2026-11-16 問い合わせと旧地図

`/contact-form`（末尾スラッシュも対応）は旧問い合わせページが公開対象の場合だけ表示する。旧ページを非公開化するか legacy_public_enabled=falseならフォームと受付APIを404にする。名前 題名 返信先メールは任意 本文のみ必須。旧Contact Form 7は名前・メールが必須だったが 身元入力を前提にせず送れる形に変更。コメント仕様には変更なし。

`POST /api/public/contact` はOrigin/CSRF検証を通したうえで 新テーブル `chronicle_contact` に非公開保存。ID・日時・未読状態をサーバー設定。メール形式 文字数 UTF-8 NUL入力を検証し HMAC化IPの時間帯別枠で1時間3件まで。罠欄に入力がある送信は保存しない。問い合わせを公開するAPI・承認操作は設けない。公開一覧やサイトマップにも出さない。フォームはエラー時入力を保持し 未送信の離脱警告を備える。

`/admin/contact` と `/api/admin/contact` は管理認証が必要。既読 未読 アーカイブ 受信一覧への復帰を改訂競合チェック付きで実装。表示はtextContentを使用し本文HTMLを実行しない。アーカイブは削除ではない。返信メール送信・通知メール・自動返信・永久削除・自動期限削除は未実装。管理者が受信一覧を確認する運用であり 旧フォームのメール転送をそのまま再現していないことに注意。

バックアップ形式3で問い合わせ全列を保存。形式1（6テーブル）と形式2（8テーブル）は欠ける新テーブルを空として復元。新規配備・更新時はsetup.phpでテーブル追加を行う。問い合わせは私的データとして元DB・画像等と同様に非公開保存し Gitには含めない。

香取市の記事（旧ID2950）のYahoo埋め込みscript2件は 実URLのlat/lonを取得しGoogle Mapsの座標検索リンクへ変換。condやhlat/hlonにある別の中心座標を使用しない。ホスト・パス・HTTPS・座標範囲を限定し 任意scriptは従来通り除去。原文は変更しない。これは地図画像の埋め込み再現ではなく「地図を開く」リンクへの置換。元の2組の座標との一致を実データで確認。

参考仕様 https://developers.google.com/maps/documentation/urls/get-started?hl=ja （2026-11-16確認）

検証：contact.phpの単体試験 HTTPの匿名受付・CSRF・管理認証・公開経路への非露出・競合・保管復帰 旧形式復元を確認。ブラウザーで名前/メール未入力の合成問い合わせを送信し 受付表示とDBの未読非公開保存を照合。メール送信は一切行っていない。管理受信一覧の実ブラウザー操作 本番ホスト MySQLは未確認。
