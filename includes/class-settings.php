<?php
/**
 * API 設定の保存・取得。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Options API を利用した設定管理。
 */
class KantanBond_Settings {

	public const OPTION_BASE_URL = 'kantanbond_api_base_url';
	public const OPTION_API_KEY  = 'kantanbond_api_key';
	public const OPTION_SECRET   = 'kantanbond_api_secret';

	/**
	 * 設定値を保存する。
	 *
	 * @param array<string, string> $input フォーム入力。
	 * @return bool
	 */
	public function save( array $input ): bool {
		$base_url   = isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : '';
		$api_key    = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '';
		$api_secret = isset( $input['api_secret'] ) ? sanitize_text_field( trim( $input['api_secret'] ) ) : '';

		update_option( self::OPTION_BASE_URL, $base_url );
		update_option( self::OPTION_API_KEY, $api_key );
		update_option( self::OPTION_SECRET, $api_secret );

		return true;
	}

	/**
	 * API Base URL を取得する。
	 *
	 * @return string
	 */
	public function get_base_url(): string {
		$value = get_option( self::OPTION_BASE_URL, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * API Key を取得する。
	 *
	 * @return string
	 */
	public function get_api_key(): string {
		$value = get_option( self::OPTION_API_KEY, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * API Secret を取得する。
	 *
	 * @return string
	 */
	public function get_api_secret(): string {
		$value = get_option( self::OPTION_SECRET, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * API 設定が完了しているか判定する。
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->get_base_url() !== ''
			&& $this->get_api_key() !== ''
			&& $this->get_api_secret() !== '';
	}

	/**
	 * 正規化した API Base URL を返す（末尾スラッシュなし）。
	 *
	 * @return string
	 */
	public function get_normalized_base_url(): string {
		$base_url = rtrim( $this->get_base_url(), '/' );

		return $base_url;
	}
}
