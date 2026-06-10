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
 * [kantanbond_customers] / [kantanbond_projects] を提供する。
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
			$created_at    = isset( $customer['created_at'] ) ? (string) $customer['created_at'] : '';

			$rows .= sprintf(
				'<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
				</tr>',
				esc_html( $id ),
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
			$amount = isset( $project['amount'] ) ? (string) $project['amount'] : '';

			$client_name = '';
			if ( isset( $project['client'] ) && is_array( $project['client'] ) ) {
				$client_name = isset( $project['client']['company_name'] )
					? (string) $project['client']['company_name']
					: '';
			}

			$due_date = isset( $project['due_date'] ) ? (string) $project['due_date'] : '';

			$rows .= sprintf(
				'<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td>%5$s</td>
					<td>%6$s</td>
				</tr>',
				esc_html( $id ),
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
