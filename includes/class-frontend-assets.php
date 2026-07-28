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
	 * Elementor 編集画面・プレビュー iframe・elementor AJAX か。
	 *
	 * このコンテキストで外部 API を呼ぶとエディタ読み込みがタイムアウトで固まる。
	 *
	 * @return bool
	 */
	public static function is_elementor_edit_context(): bool {
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( is_admin() ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $action === 'elementor' ) {
				return true;
			}
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $action !== '' && str_starts_with( $action, 'elementor' ) ) {
				return true;
			}
		}

		if ( class_exists( '\Elementor\Plugin', false ) ) {
			try {
				$plugin = \Elementor\Plugin::$instance;
				if ( isset( $plugin->editor ) && is_object( $plugin->editor )
					&& method_exists( $plugin->editor, 'is_edit_mode' )
					&& $plugin->editor->is_edit_mode() ) {
					return true;
				}
				if ( isset( $plugin->preview ) && is_object( $plugin->preview )
					&& method_exists( $plugin->preview, 'is_preview_mode' )
					&& $plugin->preview->is_preview_mode() ) {
					return true;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Elementor 未初期化時は無視。
			}
		}

		return false;
	}

	/**
	 * Elementor 編集コンテキスト向けの軽量プレースホルダー HTML。
	 *
	 * @param string $label 表示ラベル。
	 * @return string
	 */
	public static function render_editor_placeholder( string $label ): string {
		$label = trim( $label );
		if ( $label === '' ) {
			$label = 'KantanBond';
		}

		return '<div class="kantanbond-editor-placeholder" style="box-sizing:border-box;border:1px dashed #c3c4c7;border-radius:6px;color:#646970;font-size:13px;line-height:1.5;margin:0.5em 0;max-width:100%;min-width:0;padding:12px 14px;width:100%;">'
			. esc_html( $label )
			. '</div>';
	}

	/**
	 * admin-ajax のうち、ショートコード描画を抑止すべきか。
	 *
	 * 通常の admin-ajax では空を返す。Elementor AJAX は呼び出し側で
	 * `is_elementor_edit_context()` を見て API 依存／静的を切り替える。
	 *
	 * @return bool
	 */
	public static function should_skip_shortcode_during_ajax(): bool {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		// Elementor AJAX はスキップしない（公開商品は edit_context でプレースホルダー化）。
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
