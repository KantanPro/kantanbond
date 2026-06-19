=== KantanBond ===
Contributors: kantanpro
Tags: kantanbiz, api, integration, crm
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress と KantanBiz（KantanBiz Cloud）を API 連携する公式連携プラグインです。

== Description ==

KantanBond は WordPress サイトと KantanBiz アプリ（https://kantanbiz.cloud/）を接続するための公式連携プラグインです。API Base URL には www なしのアプリ本体 URL を指定してください（https://www.kantanbiz.cloud は WordPress サイトです）。

主な機能:

* 管理画面ダッシュボード
* API 設定（Base URL / API アクセストークン / API Secret）
* 同期ログの記録・閲覧
* ショートコードによる顧客・案件・商品・レポートデータの表示

将来的な拡張予定:

* 顧客管理
* 案件管理
* 売上管理
* WordPress ユーザー連携
* WooCommerce 連携
* Contact Form 7 連携
* REST API 連携
* Webhook 受信
* 会員サイト連携

== Installation ==

1. `KantanBond` フォルダを `/wp-content/plugins/` にアップロードします。
2. WordPress 管理画面の「プラグイン」から KantanBond を有効化します。
3. 「KantanBond > API設定」で KantanBiz の API 情報を入力して保存します。

== Frequently Asked Questions ==

= API アクセストークンと API Secret はどこで取得できますか？ =

KantanBiz のプロフィール画面（/profile）から取得できます。API アクセストークンは「API アクセストークン」セクションから発行してください。API Secret には KantanBiz 連携時はオフィス ID（「所有オフィス」「所属オフィス」一覧の ID 表示、例: ID: 3 / slug の 3）を入力します。

= ショートコードはどう使いますか？ =

固定ページや投稿に `[kantanbond_customers]`、`[kantanbond_projects]`、`[kantanbond_products]`、または `[kantanbond_reports]` を記述してください。`[kantanbond_services]` は `[kantanbond_products]` の別名です。

公開商品（サイト公開フラグ ON のみ・お申込みフォーム付き）: `[kantanbond_public_products]`。API 設定の「インバウンドトークン」が必要です（KantanBiz の問い合わせ受信設定で発行）。

レポート例: `[kantanbond_reports type="sales" period="this_year"]`、`[kantanbond_reports type="tax_return" tax_year="2025"]`

== Changelog ==

= 1.3.9 =
* [kantanbond_public_products] 完売・保留中商品のステータスが翻訳キー（services.availability.sold_out 等）のまま表示される問題を修正
* API の status_label が未翻訳キーの場合に「完売御礼！」「保留中」等の日本語へ変換（PHP・JS 両対応）

= 1.3.8 =
* API設定に「公開商品カードの背景色」を追加（[kantanbond_public_products] のグリッド型・カード型一覧に反映）

= 1.3.7 =
* [kantanbond_public_products] KantanBiz の「公開用HTML」をグリッド・カード・詳細モーダルに表示
* [kantanbond_public_products] カード背景色を KantanProEX に合わせて統一（#e2e8f0・画像エリア含む）

= 1.3.6 =
* [kantanbond_public_products] 数量固定時に数量欄がテーマ CSS で表示されたままになる問題を修正（display: none を明示）

= 1.3.5 =
* [kantanbond_public_products] KantanBiz の「公開フォームの数量」設定（1固定）に対応
* 数量固定商品ではお問い合わせフォームの数量欄を非表示にし、送信数量を1に固定

= 1.3.4 =
* [kantanbond_public_products] 画像拡大ライトボックスで拡大画像が画面左に寄る問題を修正
* ライトボックスに中央配置用 frame ラッパーを追加し、画像を画面中央に表示
* 画像の最大高さを 100dvh 基準に調整
* 計2ファイル・26行増・10行減（v1.3.3…HEAD）

= 1.3.3 =
* [kantanbond_public_products] 各商品ブロック下部に「問い合わす」ボタンを追加
* ボタンクリックでお問い合わせモーダルを開き、フォームまでスクロールして入力欄にフォーカス
* グリッド・カード・テーブルレイアウトすべてに対応
* 受付停止・完売・保留中商品はボタンを無効化してステータスを表示
* ブロック全体クリックでのモーダル表示を廃止し、問い合わせ操作を明確化
* 計3ファイル・134行増・25行減（v1.3.2…HEAD）

= 1.3.2 =
* [kantanbond_public_products] 定期契約タイプのモーダルで閉じる（×）ボタンが画面上部に隠れる問題を修正
* 閉じるボタンをパネル上部の専用ツールバーへ移動（position: fixed を廃止）
* モーダルをツールバー＋スクロールパネルの縦 flex レイアウトに変更
* iOS 向けスクロールロックを html の overflow 制御に変更し、モーダルを documentElement 直下にマウント
* 画像ライトボックスも同様のツールバー構成に統一
* 計3ファイル・91行増・66行減（v1.3.1…HEAD）

= 1.3.1 =
* [kantanbond_public_products] 閉じる（×）ボタンが画面上部に隠れる問題を修正
* 閉じるボタンをパネル外へ移動し、画面右上に fixed 固定（safe-area 対応）
* モーダルを全画面サイズで上寄せ表示に統一し、パネル高さを 100dvh 基準で制限
* iOS 向けスクロールロックを改善（スクロール位置の保存・復元）
* 画像ライトボックスの閉じるボタンも fixed 固定に変更
* 計3ファイル・54行増・43行減（v1.3.0…HEAD）

= 1.3.0 =
* [kantanbond_public_products] モバイルでお問い合わせモーダルを閉じられない問題を修正（一部端末で閉じるボタンが画面外に出る不具合）
* モーダルに固定ヘッダー（商品詳細＋閉じる）を追加し、スクロール中も閉じる操作を常時表示
* モバイル向けに閉じるボタンのタップ領域拡大、フォーム下部の「閉じる」ボタン追加、safe-area 対応
* 画像ライトボックスとカテゴリ絞り込み UI のモバイル表示を改善
* 計4ファイル・約170行増・20行減（v1.2.9…HEAD）

= 1.2.9 =
* [kantanbond_public_products] の `category` 属性で複数カテゴリーをカンマ区切り指定可能に（例: `category="サポート,WEB制作"`）
* 複数カテゴリー指定時は KantanBiz API へ OR 条件で問い合わせ
* `category` 指定時、絞り込み UI のサジェストを指定カテゴリーのみに限定
* ショートコード設定画面の属性説明・記述例を更新
* 計3ファイル・約80行増・10行減（v1.2.8…HEAD）

= 1.2.8 =
* 機能変更なし（v1.2.7 以降のコード変更なし）

= 1.2.7 =
* [kantanbond_public_products] 公開商品に初回費用（initial_fees）と定期費用（recurring_items）の表示を追加（グリッド・カード・テーブル・詳細モーダル）
* 商品ステータス（保留中・完売御礼）のラベル表示、画像オーバーレイ、受付停止時の通知文言を実装
* 受付停止商品の画像にグレースケール＋オーバーレイバッジを表示
* 計3ファイル・599行増・63行減（v1.2.6…HEAD）

= 1.2.6 =
* [kantanbond_public_products] お問い合わせモーダルの幅を拡大（最大1100px・画面端に余白を確保）
* モーダル内の商品画像を全幅表示に変更（縦並びレイアウト・画像ラップ要素追加）
* 画像の最大高さを拡大（max-height: min(70vh, 720px)）
* 計2ファイル・22行増・7行減（v1.2.5…HEAD）

= 1.2.5 =
* [kantanbond_public_products] 公開商品一覧のデザインを KantanProEX（ktpwp_public_products）に合わせて統一
* グリッド・カード・テーブルレイアウトのスタイルを改善（画像サイズ・余白・メモ表示など）
* 商品画像をボタンでラップし、クリックでキャプション付きライトボックス拡大表示（商品詳細モーダルと分離）
* 価格表記を「255,253円」形式に変更
* お問い合わせフォーム送信時の AJAX エラーハンドリングを改善（セッション期限切れ対応）

= 1.2.4 =
* [kantanbond_public_products] モーダルフォームのラベルを「お申し込み」から「お問い合わせ」に変更

= 1.2.3 =
* [kantanbond_public_products] 商品メモをグリッド・カード・テーブル・詳細モーダルに表示（show_memo 属性対応）

= 1.2.2 =
* GitHub zipball 更新後にプラグインフォルダ名が `KantanPro-kantanbond-*` のまま残り有効化に失敗する問題を修正（`KantanBond` へ正規化）

= 1.2.1 =
* [kantanbond_public_products] 商品画像をクリックで拡大表示（ライトボックス）

= 1.2.0 =
* [kantanbond_public_products] ショートコードを追加（公開商品一覧・モーダルお申込み）
* KantanBiz インバウンド API 連携（サーバー側プロキシ・インバウンドトークン設定）

= 1.1.2 =
* 更新チェック時に update_plugins transient が false の場合に Fatal error になる不具合を修正

= 1.1.1 =
* GitHub Releases 連携による WordPress 管理画面の更新通知・ワンクリック更新に対応
* 更新時の自動再有効化、zipball 展開後の KantanBond フォルダへのリネームに対応

= 1.1.0 =
* レポートショートコード `[kantanbond_reports]` を追加（KantanBiz Report API 連携、Chart.js グラフ表示）
* 確定申告用売上台帳（type=tax_return）の表表示に対応

= 1.0.9 =
* API Base URL の初期値を https://kantanbiz.cloud に設定
* 設定画面のヘルプ見出し・説明を整理（KantanBiz 向け取得手順、重複説明の削除）
* ダッシュボードに公開ページ設置時の注意書きを追加

= 1.0.8 =
* ショートコードの ID をクリックで KantanBiz の詳細ページへ別タブ遷移

= 1.0.7 =
* 単価・金額を ￥ 付き・3桁カンマ表示に変更（金額は小数点以下を四捨五入）

= 1.0.6 =
* 登録日・納期などの日付表示を Y-m-d 形式（例: 2026-04-15）に統一

= 1.0.5 =
* 商品画像 URL が /storage/... の相対パスの場合、API Base URL を付与して正しく表示

= 1.0.4 =
* 商品一覧ショートコード `[kantanbond_products]` を追加（API: GET /api/v1/services）
* `[kantanbond_services]` を別名として登録

= 1.0.3 =
* API Base URL のデフォルト例とプロフィールリンクを https://kantanbiz.cloud（アプリ本体）に修正

= 1.0.2 =
* 設定項目名「オフィス ID」を「API Secret」に戻し、KantanBiz 向けの入力説明をヘルプに記載

= 1.0.1 =
* 設定項目名を KantanBiz 仕様に合わせて変更（API Key → API アクセストークン、API Secret → オフィス ID）
* X-Tenant-Id ヘッダの送信に対応
* テナント ID の確認方法を API 設定画面に表示

= 1.0.0 =
* 初回リリース
* 管理画面（ダッシュボード / API 設定 / 同期ログ）
* KantanBiz API 連携基盤
* ショートコード `[kantanbond_customers]` / `[kantanbond_projects]`
