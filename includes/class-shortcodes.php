<?php
/**
 * ショートコード登録とフロント出力。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [kantanbond_customers] / [kantanbond_projects] / [kantanbond_products] を提供する。
 */
class KantanBond_Shortcodes {

	/**
	 * API クライアント。
	 *
	 * @var KantanBond_API
	 */
	private KantanBond_API $api;

	/**
	 * @param KantanBond_API $api API クライアント。
	 */
	public function __construct( KantanBond_API $api ) {
		$this->api = $api;
	}

	/**
	 * ショートコードを登録する。
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'kantanbond_customers', array( $this, 'render_customers' ) );
		add_shortcode( 'kantanbond_projects', array( $this, 'render_projects' ) );
		add_shortcode( 'kantanbond_products', array( $this, 'render_products' ) );
		add_shortcode( 'kantanbond_services', array( $this, 'render_products' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * フロントエンド用アセットを読み込む。
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'kantanbond-public',
			KANTANBOND_PLUGIN_URL . 'assets/css/public.css',
			array(),
			KANTANBOND_VERSION
		);
	}

	/**
	 * 顧客一覧ショートコード。
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_customers( array $atts = array() ): string {
		$customers = $this->api->get_customers();

		if ( is_wp_error( $customers ) ) {
			return $this->render_error_message( $customers->get_error_message() );
		}

		if ( empty( $customers ) ) {
			return $this->wrap_output(
				'<p class="kantanbond-empty">' . esc_html__( '顧客データがありません。', 'kantanbond' ) . '</p>'
			);
		}

		$rows = '';

		foreach ( $customers as $customer ) {
			$id            = isset( $customer['id'] ) ? (string) $customer['id'] : '';
			$company_name  = isset( $customer['company_name'] ) ? (string) $customer['company_name'] : '';
			$mail_target   = isset( $customer['mail_target'] ) ? (string) $customer['mail_target'] : '';
			$created_at    = isset( $customer['created_at'] ) ? $this->format_date_display( (string) $customer['created_at'] ) : '';
			$id_cell       = $this->render_id_link( $id, $this->api->get_client_url( (int) $id ) );

			$rows .= sprintf(
				'<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
				</tr>',
				$id_cell,
				esc_html( $company_name ),
				esc_html( $mail_target ),
				esc_html( $created_at )
			);
		}

		$table = sprintf(
			'<table class="kantanbond-table kantanbond-customers">
				<thead>
					<tr>
						<th scope="col">%1$s</th>
						<th scope="col">%2$s</th>
						<th scope="col">%3$s</th>
						<th scope="col">%4$s</th>
					</tr>
				</thead>
				<tbody>%5$s</tbody>
			</table>',
			esc_html__( 'ID', 'kantanbond' ),
			esc_html__( '会社名', 'kantanbond' ),
			esc_html__( 'メール送信先', 'kantanbond' ),
			esc_html__( '登録日', 'kantanbond' ),
			$rows
		);

		return $this->wrap_output( $table );
	}

	/**
	 * 案件一覧ショートコード。
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_projects( array $atts = array() ): string {
		$projects = $this->api->get_projects();

		if ( is_wp_error( $projects ) ) {
			return $this->render_error_message( $projects->get_error_message() );
		}

		if ( empty( $projects ) ) {
			return $this->wrap_output(
				'<p class="kantanbond-empty">' . esc_html__( '案件データがありません。', 'kantanbond' ) . '</p>'
			);
		}

		$rows = '';

		foreach ( $projects as $project ) {
			$id     = isset( $project['id'] ) ? (string) $project['id'] : '';
			$title  = isset( $project['title'] ) ? (string) $project['title'] : '';
			$status = isset( $project['status'] ) ? (string) $project['status'] : '';
			$amount = isset( $project['amount'] ) ? $this->format_yen_display( (string) $project['amount'], true ) : '';

			$client_name = '';
			if ( isset( $project['client'] ) && is_array( $project['client'] ) ) {
				$client_name = isset( $project['client']['company_name'] )
					? (string) $project['client']['company_name']
					: '';
			}

			$due_date = isset( $project['due_date'] ) ? $this->format_date_display( (string) $project['due_date'] ) : '';
			$id_cell  = $this->render_id_link( $id, $this->api->get_order_url( (int) $id ) );

			$rows .= sprintf(
				'<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td>%5$s</td>
					<td>%6$s</td>
				</tr>',
				$id_cell,
				esc_html( $title ),
				esc_html( $client_name ),
				esc_html( $status ),
				esc_html( $amount ),
				esc_html( $due_date )
			);
		}

		$table = sprintf(
			'<table class="kantanbond-table kantanbond-projects">
				<thead>
					<tr>
						<th scope="col">%1$s</th>
						<th scope="col">%2$s</th>
						<th scope="col">%3$s</th>
						<th scope="col">%4$s</th>
						<th scope="col">%5$s</th>
						<th scope="col">%6$s</th>
					</tr>
				</thead>
				<tbody>%7$s</tbody>
			</table>',
			esc_html__( 'ID', 'kantanbond' ),
			esc_html__( '案件名', 'kantanbond' ),
			esc_html__( '顧客', 'kantanbond' ),
			esc_html__( 'ステータス', 'kantanbond' ),
			esc_html__( '金額', 'kantanbond' ),
			esc_html__( '納期', 'kantanbond' ),
			$rows
		);

		return $this->wrap_output( $table );
	}

	/**
	 * 商品一覧ショートコード。
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_products( array $atts = array() ): string {
		$products = $this->api->get_products();

		if ( is_wp_error( $products ) ) {
			return $this->render_error_message( $products->get_error_message() );
		}

		if ( empty( $products ) ) {
			return $this->wrap_output(
				'<p class="kantanbond-empty">' . esc_html__( '商品データがありません。', 'kantanbond' ) . '</p>'
			);
		}

		$rows = '';

		foreach ( $products as $product ) {
			$id       = isset( $product['id'] ) ? (string) $product['id'] : '';
			$name     = isset( $product['name'] ) ? (string) $product['name'] : '';
			$category = isset( $product['category'] ) ? (string) $product['category'] : '';
			$price    = isset( $product['price'] ) ? $this->format_yen_display( (string) $product['price'] ) : '';
			$unit     = isset( $product['unit'] ) ? (string) $product['unit'] : '';
			$tax_rate = isset( $product['tax_rate'] ) && $product['tax_rate'] !== null
				? (string) $product['tax_rate']
				: '';

			$image_cell = '';
			if ( isset( $product['image_url'] ) && is_string( $product['image_url'] ) && $product['image_url'] !== '' ) {
				$resolved_image_url = $this->api->resolve_asset_url( $product['image_url'] );

				if ( $resolved_image_url !== '' ) {
					$image_cell = sprintf(
						'<img src="%1$s" alt="%2$s" class="kantanbond-product-image" loading="lazy" decoding="async" width="48" height="48" />',
						esc_url( $resolved_image_url ),
						esc_attr( $name )
					);
				}
			}

			$id_cell = $this->render_id_link( $id, $this->api->get_service_url( (int) $id ) );

			$rows .= sprintf(
				'<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td>%5$s</td>
					<td>%6$s</td>
					<td>%7$s</td>
				</tr>',
				$id_cell,
				$image_cell,
				esc_html( $name ),
				esc_html( $category ),
				esc_html( $price ),
				esc_html( $unit ),
				esc_html( $tax_rate )
			);
		}

		$table = sprintf(
			'<table class="kantanbond-table kantanbond-products">
				<thead>
					<tr>
						<th scope="col">%1$s</th>
						<th scope="col">%2$s</th>
						<th scope="col">%3$s</th>
						<th scope="col">%4$s</th>
						<th scope="col">%5$s</th>
						<th scope="col">%6$s</th>
						<th scope="col">%7$s</th>
					</tr>
				</thead>
				<tbody>%8$s</tbody>
			</table>',
			esc_html__( 'ID', 'kantanbond' ),
			esc_html__( '画像', 'kantanbond' ),
			esc_html__( '商品名', 'kantanbond' ),
			esc_html__( 'カテゴリ', 'kantanbond' ),
			esc_html__( '単価', 'kantanbond' ),
			esc_html__( '単位', 'kantanbond' ),
			esc_html__( '税率（%）', 'kantanbond' ),
			$rows
		);

		return $this->wrap_output( $table );
	}

	/**
	 * ID 列を KantanBiz へのリンクとして出力する。
	 *
	 * @param string $id  表示 ID。
	 * @param string $url リンク先 URL。
	 * @return string
	 */
	private function render_id_link( string $id, string $url ): string {
		if ( $id === '' ) {
			return '';
		}

		if ( $url === '' ) {
			return esc_html( $id );
		}

		return sprintf(
			'<a href="%1$s" class="kantanbond-id-link" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			esc_html( $id )
		);
	}

	/**
	 * 金額・単価を ￥ 付き・3桁カンマ形式で返す。
	 *
	 * @param string $value        数値文字列。
	 * @param bool   $round_amount 金額用。小数点以下を四捨五入する。
	 * @return string
	 */
	private function format_yen_display( string $value, bool $round_amount = false ): string {
		$value = trim( $value );

		if ( $value === '' || ! is_numeric( $value ) ) {
			return '';
		}

		$number = (float) $value;

		if ( $round_amount ) {
			$formatted = number_format( (int) round( $number ), 0, '.', ',' );
		} elseif ( abs( $number - round( $number ) ) < 0.00001 ) {
			$formatted = number_format( (int) round( $number ), 0, '.', ',' );
		} else {
			$formatted = number_format( $number, 2, '.', ',' );
		}

		return '￥' . $formatted;
	}

	/**
	 * 日付文字列を Y-m-d 形式で返す。
	 *
	 * @param string $value API から返る日時文字列。
	 * @return string
	 */
	private function format_date_display( string $value ): string {
		$value = trim( $value );

		if ( $value === '' ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $matches ) ) {
			return $matches[1];
		}

		return $value;
	}

	/**
	 * エラーメッセージ HTML を返す。
	 *
	 * @param string $message エラーメッセージ。
	 * @return string
	 */
	private function render_error_message( string $message ): string {
		return $this->wrap_output(
			'<p class="kantanbond-error" role="alert">' . esc_html( $message ) . '</p>'
		);
	}

	/**
	 * 出力をラップする。
	 *
	 * @param string $inner 内部 HTML。
	 * @return string
	 */
	private function wrap_output( string $inner ): string {
		return '<div class="kantanbond-shortcode">' . $inner . '</div>';
	}
}
