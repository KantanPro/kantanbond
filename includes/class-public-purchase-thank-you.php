<?php
/**
 * 公開商品の Stripe 決済完了（サンクス）ショートコード。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [kantanbond_public_purchase_thank_you] — Stripe Checkout 完了後の表示。
 */
class KantanBond_Public_Purchase_Thank_You {

	public const OPTION_PAGE_ID = 'kantanbond_public_purchase_thank_you_page_id';

	public const PAGE_SLUG = 'kantanbond-purchase-thank-you';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'kantanbond_public_purchase_thank_you', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'maybe_ensure_page' ), 20 );
	}

	/**
	 * サンクスページを用意する。
	 *
	 * @return void
	 */
	public function maybe_ensure_page(): void {
		self::ensure_page();
	}

	/**
	 * サンクスページ ID（なければ作成）。
	 *
	 * @return int
	 */
	public static function ensure_page(): int {
		$page_id = absint( get_option( self::OPTION_PAGE_ID, 0 ) );
		if ( $page_id > 0 ) {
			$post = get_post( $page_id );
			if ( $post instanceof WP_Post && $post->post_status !== 'trash' ) {
				return $page_id;
			}
		}

		$existing = get_page_by_path( self::PAGE_SLUG, OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			update_option( self::OPTION_PAGE_ID, (int) $existing->ID, false );

			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'ご購入ありがとうございました', 'kantanbond' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '[kantanbond_public_purchase_thank_you]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => self::resolve_page_author_id(),
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}

		update_option( self::OPTION_PAGE_ID, (int) $page_id, false );

		return (int) $page_id;
	}

	/**
	 * サンクスページ URL。
	 *
	 * @return string
	 */
	public static function get_page_url(): string {
		$page_id = self::ensure_page();
		if ( $page_id <= 0 ) {
			return home_url( '/' );
		}

		$url = get_permalink( $page_id );

		return is_string( $url ) && $url !== '' ? $url : home_url( '/' );
	}

	/**
	 * Stripe success_url 用。
	 *
	 * @return string
	 */
	public static function get_success_url(): string {
		return add_query_arg(
			array(
				'session_id' => '{CHECKOUT_SESSION_ID}',
			),
			self::get_page_url()
		);
	}

	/**
	 * Stripe cancel_url 用。
	 *
	 * @param string $return_url 戻り先 URL。
	 * @return string
	 */
	public static function get_cancel_url( string $return_url = '' ): string {
		$args = array(
			'kb_checkout' => 'cancelled',
		);

		$return_url = esc_url_raw( $return_url );
		if ( $return_url !== '' ) {
			$args['return_url'] = $return_url;
		}

		return add_query_arg( $args, self::get_page_url() );
	}

	/**
	 * ショートコード出力。
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_shortcode( array $atts = array() ): string {
		unset( $atts );

		$checkout_flag = isset( $_GET['kb_checkout'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['kb_checkout'] ) ) : '';
		$return_url    = isset( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['return_url'] ) ) : '';

		$is_failure = in_array( $checkout_flag, array( 'cancelled', 'failed' ), true );
		$modifier   = $is_failure ? ' kantanbond-purchase-thank-you--failed' : '';

		$html = '<div class="kantanbond-purchase-thank-you' . esc_attr( $modifier ) . '">';

		if ( $is_failure ) {
			$html .= '<p class="kantanbond-purchase-thank-you__lead kantanbond-purchase-thank-you__lead--error">'
				. esc_html__( '決済できませんでした。もう一度お試しください。', 'kantanbond' )
				. '</p>';
		} else {
			$html .= '<h2 class="kantanbond-purchase-thank-you__title">'
				. esc_html__( 'ご購入ありがとうございました', 'kantanbond' )
				. '</h2>';
			$html .= '<p class="kantanbond-purchase-thank-you__lead">'
				. esc_html__( 'お支払いが完了しました。', 'kantanbond' )
				. '</p>';
		}

		if ( $return_url !== '' ) {
			$html .= '<p class="kantanbond-purchase-thank-you__actions"><a class="kantanbond-purchase-thank-you__back" href="'
				. esc_url( $return_url )
				. '">'
				. esc_html__( '商品一覧へ戻る', 'kantanbond' )
				. '</a></p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * 固定ページ作成時の著者 ID。
	 *
	 * @return int
	 */
	private static function resolve_page_author_id(): int {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => array( 'ID' ),
			)
		);

		if ( ! empty( $users[0]->ID ) ) {
			return (int) $users[0]->ID;
		}

		return (int) get_current_user_id();
	}
}
