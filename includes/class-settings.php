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

	public const OPTION_BASE_URL  = 'kantanbond_api_base_url';
	public const OPTION_API_TOKEN = 'kantanbond_api_token';
	public const OPTION_SECRET    = 'kantanbond_api_secret';

	/** @deprecated 1.0.1 の一時オプションキー（後方互換用） */
	public const OPTION_TENANT_ID = 'kantanbond_tenant_id';

	/** @deprecated 1.0.0 以前のオプションキー（後方互換用） */
	public const OPTION_API_KEY = 'kantanbond_api_key';

	/**
	 * KantanBiz プロフィール画面 URL（テナント ID 確認用）。
	 */
	public const KANTANBIZ_APP_URL = 'https://kantanbiz.cloud';

	public const KANTANBIZ_PROFILE_URL = 'https://kantanbiz.cloud/profile';

	/**
	 * 設定値を保存する。
	 *
	 * @param array<string, string> $input フォーム入力。
	 * @return bool
	 */
	public function save( array $input ): bool {
		$base_url   = isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : '';
		$api_token  = isset( $input['api_token'] ) ? sanitize_text_field( trim( $input['api_token'] ) ) : '';
		$api_secret = isset( $input['api_secret'] ) ? sanitize_text_field( trim( $input['api_secret'] ) ) : '';

		if ( $base_url === '' ) {
			$base_url = self::KANTANBIZ_APP_URL;
		}

		update_option( self::OPTION_BASE_URL, $base_url );
		update_option( self::OPTION_API_TOKEN, $api_token );
		update_option( self::OPTION_SECRET, $api_secret );

		delete_option( self::OPTION_API_KEY );
		delete_option( self::OPTION_TENANT_ID );

		return true;
	}

	/**
	 * API Base URL を取得する。
	 *
	 * @return string
	 */
	public function get_base_url(): string {
		$value = get_option( self::OPTION_BASE_URL, '' );

		if ( is_string( $value ) && $value !== '' ) {
			return $value;
		}

		return self::KANTANBIZ_APP_URL;
	}

	/**
	 * API アクセストークン（Personal Access Token）を取得する。
	 *
	 * @return string
	 */
	public function get_api_token(): string {
		$value = get_option( self::OPTION_API_TOKEN, '' );

		if ( is_string( $value ) && $value !== '' ) {
			return $value;
		}

		$legacy = get_option( self::OPTION_API_KEY, '' );

		return is_string( $legacy ) ? $legacy : '';
	}

	/**
	 * API Secret を取得する。
	 *
	 * KantanBiz 連携時はオフィス ID（X-Tenant-Id）を設定する。
	 *
	 * @return string
	 */
	public function get_api_secret(): string {
		$value = get_option( self::OPTION_SECRET, '' );

		if ( is_string( $value ) && $value !== '' ) {
			return $value;
		}

		$legacy = get_option( self::OPTION_TENANT_ID, '' );

		return is_string( $legacy ) ? $legacy : '';
	}

	/**
	 * API 設定が完了しているか判定する。
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->get_base_url() !== ''
			&& $this->get_api_token() !== ''
			&& $this->get_api_secret() !== '';
	}

	/**
	 * 正規化した API Base URL を返す（末尾スラッシュなし）。
	 *
	 * @return string
	 */
	public function get_normalized_base_url(): string {
		return rtrim( $this->get_base_url(), '/' );
	}

	/**
	 * KantanBiz 上の相対パスを絶対 URL に変換する。
	 *
	 * image_url 等は /storage/... の相対パスで返ることがあるため、
	 * WordPress サイト基準ではなく API Base URL を基準に解決する。
	 *
	 * @param string $url 画像 URL またはパス。
	 * @return string
	 */
	public function resolve_app_asset_url( string $url ): string {
		$url = trim( $url );

		if ( $url === '' ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		if ( str_starts_with( $url, '//' ) ) {
			return 'https:' . $url;
		}

		$base = $this->get_normalized_base_url();

		if ( $base === '' ) {
			return $url;
		}

		return $base . '/' . ltrim( $url, '/' );
	}
}
