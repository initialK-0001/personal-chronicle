# Brand-new TOKYO リニューアル試作室

2026-11-15。第0段階の調査・設計・UI比較。既存WordPressへの変更はしていません。

- `docs/PHASE0.md`：7項目の調査・設計成果。事実／未確認／提案を区別。
- `docs/STATUS.md`：現状と再開点。
- `docs/VERIFICATION.md`：検証記録。
- `docs/public-url-inventory.json`：サイトマップ由来のURL・画像参照台帳。完全バックアップではありません。
- `dist/index.html`：カタログ型UI試作品。`dist/report.html`：設計資料の閲覧版。

## 動かす

依存ライブラリ不要。`dist` をHTTPサーバーで配信するだけです。たとえば `python3 -m http.server 8000 --directory dist`。ファイルを直接開くのではなくHTTPで開きます。画像は同梱。

構文確認：`node --check dist/app.js`。

本番の管理者認証、DB、コメント、広告、予約公開、移行機能は未実装。下書きはIndexedDBに保存される端末内デモで、実際の公開操作を行いません。画像・文章・見本を操作してUIの方向を決めるための試作品です。

## 次の開発者へ

本格実装に進む前に、ユーザーのUI確認が必要です。WordPress停止・削除・DNS切替は別途明示承認が必要です。未承認の私的データを公開しないこと、評価指標を表示しないことを最優先してください。

## 保存先と復元

GitHub: https://github.com/initialK-0001/personal-chronicle

今回のソース、設計、検証記録、公開ブログ由来の見本画像をこのリポジトリに記録します。入力した下書き・非公開画像・認証情報は含みません。配信先固有の設定はGitHubには含めず、汎用HTTPホストで `dist` を配信すれば試作を復元できます。サイト固有の配備設定は開発環境側で管理します。

資料を再生成する場合は `scripts/render-report.mjs` を使います（Node.jsとmarkedが必要）。通常の表示は生成済みHTMLで動作し、追加依存は不要です。`python3 scripts/package-source.py` で持ち出し用ソースZIPを作成できます。

## 現在のUI方針

旧A/B案は利用者のフィードバックで廃止。検索・テーマ・整列した画像一覧を中心に変更しました。サイトの操作文言には句読点を使わず短く具体的に表記します。投稿本文・チャット・技術資料の句読点は保持します。現在の試作は再確認待ちです。
