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
 * [kantanbond_customers] / [kantanbond_projects] / [kantanbond_products] / [kantanbond_reports] を提供する。
 */
class KantanBond_Shortcodes {

	/** @var list<string> */
	private const REPORT_TYPES = array(
		'sales',
		'client',
		'service',
		'supplier',
		'progress',
		'staff_contribution',
		'tax_return',
	);

	/** @var list<string> */
	private const PERIOD_KEYS = array(
		'all_time',
		'this_year',
		'last_year',
		'this_month',
		'last_month',
		'last_3_months',
		'last_6_months',
	);

	/**
	 * レポート用アセットを同一ページで一度だけ読み込む。
	 *
	 * @var bool
	 */
	private static bool $report_assets_enqueued = false;

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
		add_shortcode( 'kantanbond_reports', array( $this, 'render_reports' ) );
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
	 * レポートショートコード。
	 *
	 * 属性: type（既定 sales）, period（既定 all_time）, tax_year（tax_return 時、既定は当年）
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_reports( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'type'      => 'sales',
				'period'    => 'all_time',
				'tax_year'  => (string) gmdate( 'Y' ),
			),
			$atts,
			'kantanbond_reports'
		);

		$type   = sanitize_key( $atts['type'] );
		$period = sanitize_key( $atts['period'] );

		if ( ! in_array( $type, self::REPORT_TYPES, true ) ) {
			return $this->render_error_message(
				sprintf(
					/* translators: %s: report type key */
					__( 'レポート種別「%s」は無効です。', 'kantanbond' ),
					$type
				)
			);
		}

		if ( $type !== 'tax_return' && ! in_array( $period, self::PERIOD_KEYS, true ) ) {
			return $this->render_error_message(
				sprintf(
					/* translators: %s: period key */
					__( '集計期間「%s」は無効です。', 'kantanbond' ),
					$period
				)
			);
		}

		$tax_year = null;
		if ( $type === 'tax_return' ) {
			$tax_year = (int) $atts['tax_year'];
			if ( $tax_year < 2020 || $tax_year > (int) gmdate( 'Y' ) + 1 ) {
				return $this->render_error_message( __( 'tax_year の値が不正です。', 'kantanbond' ) );
			}
		}

		$report = $this->api->get_report( $type, $period, $tax_year );

		if ( is_wp_error( $report ) ) {
			return $this->render_error_message( $report->get_error_message() );
		}

		$root_id = wp_unique_id( 'kantanbond-report-' );
		$parts   = array();

		$parts[] = $this->build_report_header( $type, $report );
		$parts[] = $this->build_report_body( $type, $report );

		$chart_data = isset( $report['chart_data'] ) && is_array( $report['chart_data'] )
			? $report['chart_data']
			: null;

		if ( $type !== 'tax_return' && $chart_data !== null ) {
			$parts[] = $this->build_report_charts( $type, $chart_data );
			$this->enqueue_report_assets();
			$parts[] = sprintf(
				'<script type="application/json" class="kantanbond-report-payload">%s</script>',
				wp_json_encode(
					array(
						'type'       => $type,
						'chart_data' => $chart_data,
					),
					JSON_UNESCAPED_UNICODE
				)
			);
		}

		$inner = sprintf(
			'<div class="kantanbond-report-root" id="%1$s">%2$s</div>',
			esc_attr( $root_id ),
			implode( '', $parts )
		);

		return '<div class="kantanbond-shortcode kantanbond-reports">' . $inner . '</div>';
	}

	/**
	 * レポート見出しを組み立てる。
	 *
	 * @param string               $type   レポート種別。
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_report_header( string $type, array $report ): string {
		$title = $this->get_report_type_label( $type );

		if ( $type === 'tax_return' ) {
			$year = isset( $report['tax_year'] ) ? (int) $report['tax_year'] : (int) gmdate( 'Y' );
			$meta = sprintf(
				/* translators: %d: tax year */
				__( '%d年 確定申告用売上台帳', 'kantanbond' ),
				$year
			);
		} else {
			$period_label = isset( $report['period_label'] ) ? (string) $report['period_label'] : '';
			$meta         = $period_label !== ''
				? sprintf(
					/* translators: 1: report title, 2: period label */
					__( '%1$s（%2$s）', 'kantanbond' ),
					$title,
					$period_label
				)
				: $title;
		}

		return sprintf(
			'<p class="kantanbond-report-meta">%s</p>',
			esc_html( $meta )
		);
	}

	/**
	 * レポート本文（サマリー・ランキング・台帳）を組み立てる。
	 *
	 * @param string               $type   レポート種別。
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_report_body( string $type, array $report ): string {
		if ( $type === 'sales' ) {
			return $this->build_sales_summary( $report );
		}

		if ( $type === 'progress' ) {
			return $this->build_progress_summary( $report );
		}

		if ( $type === 'staff_contribution' ) {
			return $this->build_staff_contribution_list( $report );
		}

		if ( $type === 'tax_return' ) {
			return $this->build_tax_ledger( $report );
		}

		if ( in_array( $type, array( 'client', 'service', 'supplier' ), true ) ) {
			return $this->build_top_five_list( $type, $report );
		}

		return '';
	}

	/**
	 * 売上サマリーカードを返す。
	 *
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_sales_summary( array $report ): string {
		if ( ! isset( $report['summary'] ) || ! is_array( $report['summary'] ) ) {
			return '';
		}

		$summary = $report['summary'];

		return sprintf(
			'<div class="kantanbond-report-summary kantanbond-report-summary--sales">
				<div class="kantanbond-report-summary-card">
					<div class="kantanbond-report-summary-label">%1$s</div>
					<div class="kantanbond-report-summary-value">%2$s</div>
				</div>
				<div class="kantanbond-report-summary-card">
					<div class="kantanbond-report-summary-label">%3$s</div>
					<div class="kantanbond-report-summary-value">%4$s</div>
				</div>
				<div class="kantanbond-report-summary-card">
					<div class="kantanbond-report-summary-label">%5$s</div>
					<div class="kantanbond-report-summary-value">%6$s</div>
				</div>
			</div>',
			esc_html__( '総売上', 'kantanbond' ),
			esc_html( $this->format_yen_display( (string) ( $summary['total_sales'] ?? 0 ), true ) ),
			esc_html__( '案件数', 'kantanbond' ),
			esc_html( number_format( (int) ( $summary['order_count'] ?? 0 ) ) . __( '件', 'kantanbond' ) ),
			esc_html__( '平均単価', 'kantanbond' ),
			esc_html( $this->format_yen_display( (string) ( $summary['avg_amount'] ?? 0 ), true ) )
		);
	}

	/**
	 * 進捗サマリーカードを返す。
	 *
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_progress_summary( array $report ): string {
		if ( ! isset( $report['summary'] ) || ! is_array( $report['summary'] ) ) {
			return '<p class="kantanbond-empty">' . esc_html__( '進捗データがありません。', 'kantanbond' ) . '</p>';
		}

		$summary  = $report['summary'];
		$by_status = isset( $summary['by_status'] ) && is_array( $summary['by_status'] )
			? $summary['by_status']
			: array();

		if ( (int) ( $summary['total'] ?? 0 ) === 0 || $by_status === array() ) {
			return '<p class="kantanbond-empty">' . esc_html__( '進捗データがありません。', 'kantanbond' ) . '</p>';
		}

		$cards = '';
		foreach ( $by_status as $status => $count ) {
			$cards .= sprintf(
				'<div class="kantanbond-report-summary-card kantanbond-report-summary-card--plain">
					<div class="kantanbond-report-summary-label">%1$s</div>
					<div class="kantanbond-report-summary-value">%2$s</div>
				</div>',
				esc_html( (string) $status ),
				esc_html( number_format( (int) $count ) . __( '件', 'kantanbond' ) )
			);
		}

		return '<div class="kantanbond-report-summary kantanbond-report-summary--progress">' . $cards . '</div>';
	}

	/**
	 * スタッフ貢献度リストを返す。
	 *
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_staff_contribution_list( array $report ): string {
		$rows = isset( $report['staff_contribution'] ) && is_array( $report['staff_contribution'] )
			? $report['staff_contribution']
			: array();

		if ( $rows === array() ) {
			return '<p class="kantanbond-empty">' . esc_html__( 'スタッフ貢献データがありません。', 'kantanbond' ) . '</p>';
		}

		$items = '';
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name  = isset( $row['staff_name'] ) ? (string) $row['staff_name'] : '';
			$sales = isset( $row['attributed_sales'] ) ? $this->format_yen_display( (string) $row['attributed_sales'], true ) : '';
			$count = isset( $row['order_count'] ) ? number_format( (int) $row['order_count'] ) . __( '件', 'kantanbond' ) : '';

			$items .= sprintf(
				'<li class="kantanbond-report-rank-item">
					<span class="kantanbond-report-rank-name">
						<span class="kantanbond-report-rank-badge">%1$d</span>
						<span>%2$s</span>
					</span>
					<span class="kantanbond-report-rank-stats">
						<span class="kantanbond-report-rank-amount">%3$s</span>
						<span class="kantanbond-report-rank-count">%4$s</span>
					</span>
				</li>',
				(int) $index + 1,
				esc_html( $name ),
				esc_html( $sales ),
				esc_html( $count )
			);
		}

		return sprintf(
			'<div class="kantanbond-report-panel">
				<h4 class="kantanbond-report-panel-title">%1$s</h4>
				<ul class="kantanbond-report-rank-list">%2$s</ul>
			</div>',
			esc_html__( 'スタッフ別貢献度', 'kantanbond' ),
			$items
		);
	}

	/**
	 * トップ5リストを返す。
	 *
	 * @param string               $type   レポート種別。
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_top_five_list( string $type, array $report ): string {
		$rows = isset( $report['top_five'] ) && is_array( $report['top_five'] )
			? $report['top_five']
			: array();

		$panel_titles = array(
			'client'   => __( '顧客別売上トップ5', 'kantanbond' ),
			'service'  => __( 'サービス別売上トップ5', 'kantanbond' ),
			'supplier' => __( '協力会社別貢献トップ5', 'kantanbond' ),
		);

		$name_keys = array(
			'client'   => 'company_name',
			'service'  => 'service_name',
			'supplier' => 'company_name',
		);

		$amount_keys = array(
			'client'   => 'total_sales',
			'service'  => 'total_sales',
			'supplier' => 'total_contribution',
		);

		if ( $rows === array() ) {
			return '<p class="kantanbond-empty">' . esc_html__( 'データがありません。', 'kantanbond' ) . '</p>';
		}

		$name_key   = $name_keys[ $type ] ?? 'name';
		$amount_key = $amount_keys[ $type ] ?? 'total_sales';
		$items      = '';

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name  = isset( $row[ $name_key ] ) ? (string) $row[ $name_key ] : '';
			$sales = isset( $row[ $amount_key ] ) ? $this->format_yen_display( (string) $row[ $amount_key ], true ) : '';
			$count = isset( $row['order_count'] ) ? number_format( (int) $row['order_count'] ) . __( '件', 'kantanbond' ) : '';

			$items .= sprintf(
				'<li class="kantanbond-report-rank-item">
					<span class="kantanbond-report-rank-name">
						<span class="kantanbond-report-rank-badge">%1$d</span>
						<span>%2$s</span>
					</span>
					<span class="kantanbond-report-rank-stats">
						<span class="kantanbond-report-rank-amount">%3$s</span>
						<span class="kantanbond-report-rank-count">%4$s</span>
					</span>
				</li>',
				(int) $index + 1,
				esc_html( $name ),
				esc_html( $sales ),
				esc_html( $count )
			);
		}

		return sprintf(
			'<div class="kantanbond-report-panel">
				<h4 class="kantanbond-report-panel-title">%1$s</h4>
				<ul class="kantanbond-report-rank-list">%2$s</ul>
			</div>',
			esc_html( $panel_titles[ $type ] ?? __( 'トップ5', 'kantanbond' ) ),
			$items
		);
	}

	/**
	 * 確定申告用売上台帳を返す。
	 *
	 * @param array<string, mixed> $report API レスポンス data。
	 * @return string
	 */
	private function build_tax_ledger( array $report ): string {
		if ( ! isset( $report['tax_ledger'] ) || ! is_array( $report['tax_ledger'] ) ) {
			return '<p class="kantanbond-empty">' . esc_html__( '売上台帳データがありません。', 'kantanbond' ) . '</p>';
		}

		$ledger = $report['tax_ledger'];
		$rows   = isset( $ledger['rows'] ) && is_array( $ledger['rows'] ) ? $ledger['rows'] : array();
		$totals = isset( $ledger['totals'] ) && is_array( $ledger['totals'] ) ? $ledger['totals'] : array();

		if ( $rows === array() ) {
			return '<p class="kantanbond-empty">' . esc_html__( '売上台帳データがありません。', 'kantanbond' ) . '</p>';
		}

		$summary = sprintf(
			'<div class="kantanbond-report-summary kantanbond-report-summary--tax">
				<div class="kantanbond-report-summary-card">
					<div class="kantanbond-report-summary-label">%1$s</div>
					<div class="kantanbond-report-summary-value">%2$s</div>
				</div>
				<div class="kantanbond-report-summary-card">
					<div class="kantanbond-report-summary-label">%3$s</div>
					<div class="kantanbond-report-summary-value">%4$s</div>
				</div>
			</div>',
			esc_html__( '年間売上合計', 'kantanbond' ),
			esc_html( $this->format_yen_display( (string) ( $totals['total_sales'] ?? 0 ), true ) ),
			esc_html__( '記録件数', 'kantanbond' ),
			esc_html( number_format( (int) ( $totals['order_count'] ?? 0 ) ) . __( '件', 'kantanbond' ) )
		);

		$table_rows = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$table_rows .= sprintf(
				'<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td class="kantanbond-report-amount-cell">%5$s</td>
				</tr>',
				esc_html( isset( $row['date'] ) ? $this->format_date_display( (string) $row['date'] ) : '' ),
				esc_html( isset( $row['client_name'] ) ? (string) $row['client_name'] : '' ),
				esc_html( isset( $row['order_title'] ) ? (string) $row['order_title'] : '' ),
				esc_html( isset( $row['status'] ) ? (string) $row['status'] : '' ),
				esc_html( $this->format_yen_display( (string) ( $row['total_amount'] ?? 0 ), true ) )
			);
		}

		$table = sprintf(
			'<table class="kantanbond-table kantanbond-tax-ledger">
				<thead>
					<tr>
						<th scope="col">%1$s</th>
						<th scope="col">%2$s</th>
						<th scope="col">%3$s</th>
						<th scope="col">%4$s</th>
						<th scope="col">%5$s</th>
					</tr>
				</thead>
				<tbody>%6$s</tbody>
			</table>',
			esc_html__( '日付', 'kantanbond' ),
			esc_html__( '顧客', 'kantanbond' ),
			esc_html__( '案件名', 'kantanbond' ),
			esc_html__( 'ステータス', 'kantanbond' ),
			esc_html__( '金額', 'kantanbond' ),
			$table_rows
		);

		return $summary . $table;
	}

	/**
	 * グラフパネル HTML を返す。
	 *
	 * @param string               $type       レポート種別。
	 * @param array<string, mixed> $chart_data グラフ用データ。
	 * @return string
	 */
	private function build_report_charts( string $type, array $chart_data ): string {
		$panels = $this->get_report_chart_panels( $type );

		if ( $panels === array() ) {
			return '';
		}

		$items = '';
		foreach ( $panels as $panel ) {
			$key = $panel['key'];
			if ( ! isset( $chart_data[ $key ] ) ) {
				continue;
			}

			$items .= sprintf(
				'<div class="kantanbond-report-chart-item">
					<h4 class="kantanbond-report-chart-title">%1$s</h4>
					<div class="kantanbond-chart-canvas-wrap">
						<canvas data-chart-key="%2$s"></canvas>
					</div>
				</div>',
				esc_html( $panel['title'] ),
				esc_attr( $key )
			);
		}

		if ( $items === '' ) {
			return '';
		}

		$grid_class = count( $panels ) === 1
			? 'kantanbond-report-charts-grid kantanbond-report-charts-grid--single'
			: 'kantanbond-report-charts-grid';

		return '<div class="' . esc_attr( $grid_class ) . '">' . $items . '</div>';
	}

	/**
	 * レポート種別の表示名を返す。
	 *
	 * @param string $type レポート種別。
	 * @return string
	 */
	private function get_report_type_label( string $type ): string {
		$labels = array(
			'sales'              => __( '売上レポート', 'kantanbond' ),
			'client'             => __( '顧客別レポート', 'kantanbond' ),
			'service'            => __( 'サービス別レポート', 'kantanbond' ),
			'supplier'           => __( '協力会社別レポート', 'kantanbond' ),
			'progress'           => __( '進捗レポート', 'kantanbond' ),
			'staff_contribution' => __( 'スタッフ貢献度レポート', 'kantanbond' ),
			'tax_return'         => __( '確定申告用売上台帳', 'kantanbond' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * レポート種別ごとのグラフ定義を返す。
	 *
	 * @param string $type レポート種別。
	 * @return list<array{key: string, title: string}>
	 */
	private function get_report_chart_panels( string $type ): array {
		return match ( $type ) {
			'sales' => array(
				array(
					'key'   => 'monthly_sales',
					'title' => __( '月別売上', 'kantanbond' ),
				),
				array(
					'key'   => 'profit_trend',
					'title' => __( '利益・コスト推移', 'kantanbond' ),
				),
			),
			'client' => array(
				array(
					'key'   => 'client_sales',
					'title' => __( '顧客別売上', 'kantanbond' ),
				),
				array(
					'key'   => 'client_orders',
					'title' => __( '顧客別案件数', 'kantanbond' ),
				),
			),
			'service' => array(
				array(
					'key'   => 'service_sales',
					'title' => __( 'サービス別売上', 'kantanbond' ),
				),
				array(
					'key'   => 'service_quantity',
					'title' => __( 'サービス別数量', 'kantanbond' ),
				),
			),
			'supplier' => array(
				array(
					'key'   => 'supplier_skills',
					'title' => __( '協力会社別スキル貢献', 'kantanbond' ),
				),
				array(
					'key'   => 'skill_suppliers',
					'title' => __( 'スキル別協力会社数', 'kantanbond' ),
				),
			),
			'progress' => array(
				array(
					'key'   => 'progress_bar',
					'title' => __( 'ステータス別件数', 'kantanbond' ),
				),
				array(
					'key'   => 'progress_share',
					'title' => __( 'ステータス別割合', 'kantanbond' ),
				),
			),
			'staff_contribution' => array(
				array(
					'key'   => 'staff_sales',
					'title' => __( 'スタッフ別売上', 'kantanbond' ),
				),
			),
			default => array(),
		};
	}

	/**
	 * Chart.js とレポート用スクリプトを読み込む。
	 *
	 * @return void
	 */
	private function enqueue_report_assets(): void {
		if ( self::$report_assets_enqueued ) {
			return;
		}

		self::$report_assets_enqueued = true;

		wp_enqueue_script(
			'chart-js',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js',
			array(),
			'4.4.6',
			true
		);

		wp_enqueue_script(
			'kantanbond-reports',
			KANTANBOND_PLUGIN_URL . 'assets/js/reports.js',
			array( 'chart-js' ),
			KANTANBOND_VERSION,
			true
		);

		wp_localize_script(
			'kantanbond-reports',
			'kantanbondReportI18n',
			array(
				'sales_amount' => __( '売上金額', 'kantanbond' ),
				'cost'         => __( 'コスト', 'kantanbond' ),
				'profit'       => __( '利益', 'kantanbond' ),
				'sales'        => __( '売上', 'kantanbond' ),
				'count'        => __( '件数', 'kantanbond' ),
			)
		);
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
