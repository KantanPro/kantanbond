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

	public const OPTION_BASE_URL       = 'kantanbond_api_base_url';
	public const OPTION_API_TOKEN      = 'kantanbond_api_token';
	public const OPTION_SECRET         = 'kantanbond_api_secret';
	public const OPTION_INBOUND_TOKEN  = 'kantanbond_inbound_token';

	public const OPTION_PUBLIC_PRODUCT_CARD_BG_COLOR = 'kantanbond_public_product_card_bg_color';

	public const DEFAULT_PUBLIC_PRODUCT_CARD_BG_COLOR = '#e2e8f0';

	/** @deprecated 1.0.1 の一時オプションキー（後方互換用） */
	public const OPTION_TENANT_ID = 'kantanbond_tenant_id';

	/** @deprecated 1.0.0 以前のオプションキー（後方互換用） */
	public const OPTION_API_KEY = 'kantanbond_api_key';

	/**
	 * KantanBiz プロフィール画面 URL（テナント ID 確認用）。
	 */
	public const KANTANBIZ_APP_URL = 'https://kantanbiz.cloud';

	public const KANTANBIZ_PROFILE_URL = 'https://kantanbiz.cloud/profile';

	/** KantanBiz 問い合わせ受信トークン発行画面（公開商品 API 用） */
	public const KANTANBIZ_CONTACT_FORM_INBOUND_URL = 'https://kantanbiz.cloud/contact-form-inbound';

	/**
	 * 設定値を保存する。
	 *
	 * @param array<string, string> $input フォーム入力。
	 * @return bool
	 */
	public function save( array $input ): bool {
		$base_url       = isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : '';
		$api_token      = isset( $input['api_token'] ) ? sanitize_text_field( trim( $input['api_token'] ) ) : '';
		$api_secret     = isset( $input['api_secret'] ) ? sanitize_text_field( trim( $input['api_secret'] ) ) : '';
		$inbound_token  = isset( $input['inbound_token'] ) ? sanitize_text_field( trim( $input['inbound_token'] ) ) : '';
		$card_bg_color  = isset( $input['public_product_card_bg_color'] )
			? sanitize_hex_color( trim( (string) $input['public_product_card_bg_color'] ) )
			: '';

		if ( $base_url === '' ) {
			$base_url = self::KANTANBIZ_APP_URL;
		}

		update_option( self::OPTION_BASE_URL, $base_url );
		update_option( self::OPTION_API_TOKEN, $api_token );
		update_option( self::OPTION_SECRET, $api_secret );
		update_option( self::OPTION_INBOUND_TOKEN, $inbound_token );
		update_option(
			self::OPTION_PUBLIC_PRODUCT_CARD_BG_COLOR,
			$card_bg_color !== '' && $card_bg_color !== false
				? $card_bg_color
				: self::DEFAULT_PUBLIC_PRODUCT_CARD_BG_COLOR
		);

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
	 * 公開商品ショートコード用のインバウンドトークンを取得する。
	 *
	 * @return string
	 */
	public function get_inbound_token(): string {
		$value = get_option( self::OPTION_INBOUND_TOKEN, '' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * 公開商品 API（インバウンドトークン）の設定が完了しているか。
	 *
	 * @return bool
	 */
	public function is_public_products_configured(): bool {
		return $this->get_normalized_base_url() !== ''
			&& $this->get_inbound_token() !== '';
	}

	/**
	 * [kantanbond_public_products] のグリッド・カード型一覧の背景色（HEX）。
	 *
	 * @return string
	 */
	public function get_public_product_card_bg_color(): string {
		$value = get_option( self::OPTION_PUBLIC_PRODUCT_CARD_BG_COLOR, self::DEFAULT_PUBLIC_PRODUCT_CARD_BG_COLOR );

		if ( ! is_string( $value ) || $value === '' ) {
			return self::DEFAULT_PUBLIC_PRODUCT_CARD_BG_COLOR;
		}

		$sanitized = sanitize_hex_color( $value );

		return $sanitized !== '' && $sanitized !== false
			? $sanitized
			: self::DEFAULT_PUBLIC_PRODUCT_CARD_BG_COLOR;
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
			return $this->to_browser_accessible_url( $url );
		}

		if ( str_starts_with( $url, '//' ) ) {
			return $this->to_browser_accessible_url( 'https:' . $url );
		}

		$base = $this->get_browser_base_url();

		if ( $base === '' ) {
			return $url;
		}

		return $this->to_browser_accessible_url( $base . '/' . ltrim( $url, '/' ) );
	}

	/**
	 * ブラウザから参照可能な KantanBiz Base URL（画像・リンク用）。
	 *
	 * API 通信は Docker 内から host.docker.internal 等を使うが、
	 * フロント HTML の src/href は訪問者のブラウザが解決する必要がある。
	 *
	 * @return string
	 */
	public function get_browser_base_url(): string {
		return $this->to_browser_accessible_url( $this->get_normalized_base_url() );
	}

	/**
	 * Docker 内部ホスト名をブラウザ向け URL に置き換える。
	 *
	 * @param string $url 解決済み URL。
	 * @return string
	 */
	private function to_browser_accessible_url( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$host = strtolower( (string) $parts['host'] );

		$docker_hosts = array(
			'host.docker.internal',
			'docker.for.mac.localhost',
			'docker.for.win.localhost',
			'gateway.docker.internal',
		);

		if ( ! in_array( $host, $docker_hosts, true ) ) {
			/**
			 * ブラウザ向け URL 変換（プラグイン拡張用）。
			 *
			 * @param string $url 変換前 URL。
			 */
			return (string) apply_filters( 'kantanbond_browser_accessible_url', $url );
		}

		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'http';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query  = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . (string) $parts['fragment'] : '';

		$rewritten = $scheme . '://127.0.0.1' . $port . $path . $query . $fragment;

		/**
		 * ブラウザ向け URL 変換（プラグイン拡張用）。
		 *
		 * @param string $rewritten 変換後 URL。
		 */
		return (string) apply_filters( 'kantanbond_browser_accessible_url', $rewritten );
	}
}
