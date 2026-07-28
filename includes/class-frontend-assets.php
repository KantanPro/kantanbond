<?php
/**
 * フロントエンド CSS/JS の登録・条件付き読込（Elementor 干渉防止）。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ショートコード未使用ページへ CSS を流さない／Elementor 内の検知を行う。
 */
class KantanBond_Frontend_Assets {

	/** @var list<string> */
	public const SHORTCODE_TAGS = array(
		'kantanbond_customers',
		'kantanbond_projects',
		'kantanbond_products',
		'kantanbond_services',
		'kantanbond_reports',
		'kantanbond_version',
		'kantanbond_public_products',
		'kantanbond_public_purchase_thank_you',
		'kantanbond_billing_plans',
		'kantanbond_plans',
	);

	/**
	 * @var bool
	 */
	private static bool $public_style_registered = false;

	/**
	 * @var bool
	 */
	private static bool $public_style_enqueued = false;

	/**
	 * public.css を登録する（未 enqueue）。
	 *
	 * @return void
	 */
	public static function register_public_style(): void {
		if ( self::$public_style_registered ) {
			return;
		}

		wp_register_style(
			'kantanbond-public',
			KANTANBOND_PLUGIN_URL . 'assets/css/public.css',
			array(),
			KANTANBOND_VERSION
		);

		self::$public_style_registered = true;
	}

	/**
	 * public.css を読み込む（多重呼び出し可）。
	 *
	 * @return void
	 */
	public static function enqueue_public_style(): void {
		if ( self::$public_style_enqueued ) {
			return;
		}

		self::register_public_style();
		wp_enqueue_style( 'kantanbond-public' );
		self::$public_style_enqueued = true;
	}

	/**
	 * 現在のリクエストで KantanBond ショートコードが使われる見込みか。
	 *
	 * Elementor は本文ではなく `_elementor_data` にショートコードを持つため、
	 * post_content だけでなくメタも走査する。
	 *
	 * @return bool
	 */
	public static function current_view_needs_public_style(): bool {
		if ( is_admin() && ! self::is_elementor_preview_request() ) {
			return false;
		}

		if ( self::is_elementor_preview_request() ) {
			return true;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			$post = get_post();
		}

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( self::content_mentions_shortcode( (string) $post->post_content ) ) {
			return true;
		}

		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $elementor_data ) && $elementor_data !== '' && self::content_mentions_shortcode( $elementor_data ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Elementor のプレビュー／エディタ描画リクエストか。
	 *
	 * @return bool
	 */
	public static function is_elementor_preview_request(): bool {
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $action !== '' && str_starts_with( $action, 'elementor' );
	}

	/**
	 * admin-ajax のうち、ショートコード描画を抑止すべきか（Elementor 以外）。
	 *
	 * @return bool
	 */
	public static function should_skip_shortcode_during_ajax(): bool {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		// Elementor エディタ／ウィジェット描画ではショートコードを実行する。
		if ( self::is_elementor_preview_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * 文字列内に KantanBond ショートコード名が含まれるか。
	 *
	 * @param string $content 投稿本文または Elementor JSON。
	 * @return bool
	 */
	public static function content_mentions_shortcode( string $content ): bool {
		if ( $content === '' ) {
			return false;
		}

		foreach ( self::SHORTCODE_TAGS as $tag ) {
			if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, $tag ) ) {
				return true;
			}

			// Elementor JSON では [tag がエスケープされることがあるため名前一致も見る。
			if ( false !== strpos( $content, $tag ) ) {
				return true;
			}
		}

		return false;
	}
}
