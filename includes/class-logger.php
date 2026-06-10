<?php
/**
 * 同期ログの記録・取得。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_kantanbond_logs テーブルへのアクセスを担当する。
 */
class KantanBond_Logger {

	public const TYPE_INFO    = 'info';
	public const TYPE_SUCCESS = 'success';
	public const TYPE_ERROR   = 'error';
	public const TYPE_API     = 'api';

	/**
	 * ログを 1 件追加する。
	 *
	 * @param string $type    ログ種別。
	 * @param string $message メッセージ。
	 * @return int|false 挿入 ID、失敗時 false。
	 */
	public function log( string $type, string $message ) {
		global $wpdb;

		$table_name = KantanBond_Installer::get_logs_table_name();
		$type       = sanitize_key( $type );
		$message    = sanitize_textarea_field( $message );

		if ( $type === '' ) {
			$type = self::TYPE_INFO;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- カスタムログテーブルへの書き込み。
		$result = $wpdb->insert(
			$table_name,
			array(
				'created_at' => current_time( 'mysql' ),
				'type'       => $type,
				'message'    => $message,
			),
			array(
				'%s',
				'%s',
				'%s',
			)
		);

		if ( $result === false ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * ログ一覧を取得する。
	 *
	 * @param int $limit  取得件数。
	 * @param int $offset オフセット。
	 * @return array<int, object>
	 */
	public function get_logs( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table_name = KantanBond_Installer::get_logs_table_name();
		$limit      = max( 1, min( 200, $limit ) );
		$offset     = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- カスタムログテーブルからの読み取り。
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, type, message FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * ログ総件数を取得する。
	 *
	 * @return int
	 */
	public function get_total_count(): int {
		global $wpdb;

		$table_name = KantanBond_Installer::get_logs_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- カスタムログテーブルの件数取得。
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		return (int) $count;
	}
}
