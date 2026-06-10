=== KantanBond ===
Contributors: kantanpro
Tags: kantanbiz, api, integration, crm
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress と KantanBiz（KantanBiz Cloud）を API 連携する公式連携プラグインです。

== Description ==

KantanBond は WordPress サイトと KantanBiz（https://www.kantanbiz.cloud/）を接続するための公式連携プラグインです。

主な機能:

* 管理画面ダッシュボード
* API 設定（Base URL / API Key / API Secret）
* 同期ログの記録・閲覧
* ショートコードによる顧客・案件データの表示

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

= API Key と API Secret はどこで取得できますか？ =

KantanBiz 管理画面の API 設定から取得してください。

= ショートコードはどう使いますか？ =

固定ページや投稿に `[kantanbond_customers]` または `[kantanbond_projects]` を記述してください。

== Changelog ==

= 1.0.0 =
* 初回リリース
* 管理画面（ダッシュボード / API 設定 / 同期ログ）
* KantanBiz API 連携基盤
* ショートコード `[kantanbond_customers]` / `[kantanbond_projects]`
