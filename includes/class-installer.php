<?php
/**
 * プラグインのインストール・アンインストール処理。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 有効化・無効化・アンインストール時の DB 操作を担当する。
 */
class KantanBond_Installer {

	/**
	 * ログテーブル名（プレフィックス付き）を返す。
	 *
	 * @global wpdb $wpdb WordPress データベースオブジェクト。
	 * @return string
	 */
	public static function get_logs_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'kantanbond_logs';
	}

	/**
	 * 有効化時の処理。
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_logs_table();
		update_option( 'kantanbond_version', KANTANBOND_VERSION );
	}

	/**
	 * 無効化時の処理。
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// 将来: スケジュールイベントの解除など。
	}

	/**
	 * アンインストール時の処理。
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		$table_name = self::get_logs_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部定義。
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		delete_option( 'kantanbond_api_base_url' );
		delete_option( 'kantanbond_api_key' );
		delete_option( 'kantanbond_api_secret' );
		delete_option( 'kantanbond_version' );
	}

	/**
	 * ログテーブルを dbDelta で作成する。
	 *
	 * @return void
	 */
	public static function create_logs_table(): void {
		global $wpdb;

		$table_name      = self::get_logs_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			type varchar(50) NOT NULL DEFAULT '',
			message text NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}
}
