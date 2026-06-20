<?php
/**
 * KantanBiz API 通信。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_remote_get / wp_remote_post を利用した API クライアント。
 */
class KantanBond_API {

	/**
	 * 設定クラス。
	 *
	 * @var KantanBond_Settings
	 */
	private KantanBond_Settings $settings;

	/**
	 * ロガー。
	 *
	 * @var KantanBond_Logger
	 */
	private KantanBond_Logger $logger;

	/**
	 * @param KantanBond_Settings $settings 設定。
	 * @param KantanBond_Logger   $logger   ロガー。
	 */
	public function __construct( KantanBond_Settings $settings, KantanBond_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * API リクエストを送信する。
	 *
	 * @param string               $method   HTTP メソッド（GET / POST / PATCH / DELETE）。
	 * @param string               $endpoint エンドポイント（/api/v1/clients 等）。
	 * @param array<string, mixed> $body     POST 等のボディ。
	 * @param array<string, mixed> $args     wp_remote_* 追加引数。
	 * @return array<string, mixed>|WP_Error
	 */
	public function request( string $method, string $endpoint, array $body = array(), array $args = array() ) {
		if ( ! $this->settings->is_configured() ) {
			$error = new WP_Error(
				'kantanbond_not_configured',
				__( 'API 設定が完了していません。', 'kantanbond' )
			);
			$this->logger->log( KantanBond_Logger::TYPE_ERROR, $error->get_error_message() );

			return $error;
		}

		$base_url = $this->settings->get_normalized_base_url();
		$endpoint = '/' . ltrim( $endpoint, '/' );
		$url      = $base_url . $endpoint;

		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->settings->get_api_token(),
			'X-Tenant-Id'   => $this->settings->get_api_secret(),
		);

		$default_args = array(
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( ! empty( $body ) ) {
			$default_args['body'] = wp_json_encode( $body );
			$default_args['headers']['Content-Type'] = 'application/json';
		}

		$request_args = wp_parse_args( $args, $default_args );
		$http_method  = $request_args['method'];

		if ( 'GET' === $http_method ) {
			unset( $request_args['method'] );
			$response = wp_remote_get( $url, $request_args );
		} else {
			$response = wp_remote_post( $url, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->log(
				KantanBond_Logger::TYPE_ERROR,
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: error message */
					__( 'API 通信エラー [%1$s %2$s]: %3$s', 'kantanbond' ),
					$http_method,
					$endpoint,
					$response->get_error_message()
				)
			);

			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			$decoded = array(
				'raw' => $raw_body,
			);
		}

		if ( $status_code >= 400 ) {
			$error_message = isset( $decoded['message'] ) && is_string( $decoded['message'] )
				? $decoded['message']
				: sprintf(
					/* translators: 1: HTTP status code */
					__( 'API がエラーを返しました（HTTP %d）。', 'kantanbond' ),
					$status_code
				);

			$this->logger->log(
				KantanBond_Logger::TYPE_ERROR,
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: status code, 4: message */
					__( 'API エラー [%1$s %2$s] HTTP %3$d: %4$s', 'kantanbond' ),
					$http_method,
					$endpoint,
					$status_code,
					$error_message
				)
			);

			return new WP_Error(
				'kantanbond_api_error',
				$error_message,
				array(
					'status'   => $status_code,
					'response' => $decoded,
				)
			);
		}

		$this->logger->log(
			KantanBond_Logger::TYPE_API,
			sprintf(
				/* translators: 1: HTTP method, 2: endpoint, 3: status code */
				__( 'API 成功 [%1$s %2$s] HTTP %3$d', 'kantanbond' ),
				$http_method,
				$endpoint,
				$status_code
			)
		);

		return $decoded;
	}

	/**
	 * 顧客（clients）一覧を取得する。
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_customers() {
		$response = $this->request( 'GET', '/api/v1/clients' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->extract_data_rows( $response );
	}

	/**
	 * 案件（orders）一覧を取得する。
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_projects() {
		$response = $this->request( 'GET', '/api/v1/orders' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->extract_data_rows( $response );
	}

	/**
	 * レポート集計・グラフデータを取得する。
	 *
	 * @param string   $type     レポート種別。
	 * @param string   $period   集計期間。
	 * @param int|null $tax_year 確定申告用の年（tax_return 時）。
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_report( string $type = 'sales', string $period = 'all_time', ?int $tax_year = null ) {
		$query = array(
			'type'   => $type,
			'period' => $period,
		);

		if ( $tax_year !== null ) {
			$query['tax_year'] = $tax_year;
		}

		$endpoint = '/api/v1/reports?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$response = $this->request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new WP_Error(
				'kantanbond_invalid_report_response',
				__( 'レポート API の応答形式が不正です。', 'kantanbond' )
			);
		}

		return $response['data'];
	}

	/**
	 * 問い合わせ受信・公開商品用インバウンド API リクエスト（サーバー側のみ。PAT は使わない）。
	 *
	 * @param string               $method   HTTP メソッド。
	 * @param string               $endpoint エンドポイント。
	 * @param array<string, mixed> $body     POST ボディ。
	 * @param array<string, mixed> $query    GET クエリ。
	 * @return array<string, mixed>|WP_Error
	 */
	public function inbound_request( string $method, string $endpoint, array $body = array(), array $query = array() ) {
		if ( ! $this->settings->is_public_products_configured() ) {
			$error = new WP_Error(
				'kantanbond_inbound_not_configured',
				__( '公開商品用の API 設定が完了していません（インバウンドトークン）。', 'kantanbond' )
			);
			$this->logger->log( KantanBond_Logger::TYPE_ERROR, $error->get_error_message() );

			return $error;
		}

		$base_url = $this->settings->get_normalized_base_url();
		$endpoint = '/' . ltrim( $endpoint, '/' );

		if ( $query !== array() ) {
			$endpoint .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$url = $base_url . $endpoint;

		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->settings->get_inbound_token(),
		);

		$default_args = array(
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( $body !== array() ) {
			$default_args['body'] = wp_json_encode( $body );
			$default_args['headers']['Content-Type'] = 'application/json';
		}

		$http_method = $default_args['method'];

		if ( 'GET' === $http_method ) {
			unset( $default_args['method'] );
			$response = wp_remote_get( $url, $default_args );
		} else {
			$response = wp_remote_post( $url, $default_args );
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->log(
				KantanBond_Logger::TYPE_ERROR,
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: error message */
					__( 'インバウンド API 通信エラー [%1$s %2$s]: %3$s', 'kantanbond' ),
					$http_method,
					$endpoint,
					$response->get_error_message()
				)
			);

			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			$decoded = array(
				'raw' => $raw_body,
			);
		}

		if ( $status_code >= 400 ) {
			$error_message = isset( $decoded['message'] ) && is_string( $decoded['message'] )
				? $decoded['message']
				: sprintf(
					/* translators: 1: HTTP status code */
					__( 'インバウンド API がエラーを返しました（HTTP %d）。', 'kantanbond' ),
					$status_code
				);

			$this->logger->log(
				KantanBond_Logger::TYPE_ERROR,
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: status code, 4: message */
					__( 'インバウンド API エラー [%1$s %2$s] HTTP %3$d: %4$s', 'kantanbond' ),
					$http_method,
					$endpoint,
					$status_code,
					$error_message
				)
			);

			return new WP_Error(
				'kantanbond_inbound_api_error',
				$error_message,
				array(
					'status'   => $status_code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * 公開商品一覧を取得する。
	 *
	 * @param string|array<int, string> $category カテゴリ絞り込み（任意。配列またはカンマ区切り文字列）。
	 * @return array{products: array<int, array<string, mixed>>, categories: array<int, string>, stripe_available: bool}|WP_Error
	 */
	public function get_public_products( $category = '' ) {
		$query = array();
		if ( is_array( $category ) ) {
			$categories = array();
			foreach ( $category as $item ) {
				$item = sanitize_text_field( (string) $item );
				if ( $item !== '' ) {
					$categories[] = $item;
				}
			}
			$categories = array_values( array_unique( $categories ) );
		} else {
			$categories = $this->parse_categories( (string) $category );
		}

		if ( $categories !== array() ) {
			$query['category'] = count( $categories ) === 1
				? $categories[0]
				: implode( ',', $categories );
		}

		$response = $this->inbound_request( 'GET', '/api/v1/inbound/public-products', array(), $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$products = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
		$categories = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
		$stripe_available = ! empty( $data['stripe_available'] );

		$normalized_products = array();
		foreach ( $products as $row ) {
			if ( is_array( $row ) ) {
				$normalized_products[] = $row;
			}
		}

		$normalized_categories = array();
		foreach ( $categories as $cat ) {
			if ( is_string( $cat ) && $cat !== '' ) {
				$normalized_categories[] = $cat;
			}
		}

		return array(
			'products'         => $normalized_products,
			'categories'       => $normalized_categories,
			'stripe_available' => $stripe_available,
		);
	}

	/**
	 * 公開商品の Web お申込みを送信する。
	 *
	 * @param array<string, mixed> $payload フォームデータ。
	 * @return array<string, mixed>|WP_Error
	 */
	public function submit_public_product_order( array $payload ) {
		$response = $this->inbound_request( 'POST', '/api/v1/inbound/public-product-orders', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * 公開商品の Stripe 即時購入を送信する。
	 *
	 * @param array<string, mixed> $payload フォームデータと URL。
	 * @return array<string, mixed>|WP_Error
	 */
	public function submit_public_product_purchase( array $payload ) {
		$response = $this->inbound_request( 'POST', '/api/v1/inbound/public-product-purchases', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * 商品（services）一覧を取得する。
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_products() {
		$response = $this->request( 'GET', '/api/v1/services' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->extract_data_rows( $response );
	}

	/**
	 * 売上（orders の amount 情報）一覧を取得する。
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_sales() {
		$response = $this->request( 'GET', '/api/v1/orders' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$orders = $this->extract_data_rows( $response );
		$sales  = array();

		foreach ( $orders as $order ) {
			$sales[] = array(
				'id'         => isset( $order['id'] ) ? $order['id'] : '',
				'title'      => isset( $order['title'] ) ? $order['title'] : '',
				'amount'     => isset( $order['amount'] ) ? $order['amount'] : '',
				'status'     => isset( $order['status'] ) ? $order['status'] : '',
				'client'     => isset( $order['client'] ) && is_array( $order['client'] )
					? ( isset( $order['client']['company_name'] ) ? $order['client']['company_name'] : '' )
					: '',
				'updated_at' => isset( $order['updated_at'] ) ? $order['updated_at'] : '',
			);
		}

		return $sales;
	}

	/**
	 * KantanBiz 上のアセット URL を絶対 URL に解決する。
	 *
	 * @param string $url 画像 URL またはパス。
	 * @return string
	 */
	public function resolve_asset_url( string $url ): string {
		return $this->settings->resolve_app_asset_url( $url );
	}

	/**
	 * KantanBiz アプリ上の URL を組み立てる。
	 *
	 * @param string $path パス（clients/1 等）。
	 * @return string
	 */
	public function build_app_url( string $path ): string {
		$base = $this->settings->get_normalized_base_url();

		if ( $base === '' ) {
			return '';
		}

		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * 顧客詳細 URL を返す。
	 *
	 * @param int $client_id 顧客 ID。
	 * @return string
	 */
	public function get_client_url( int $client_id ): string {
		if ( $client_id <= 0 ) {
			return '';
		}

		return $this->build_app_url( 'clients/' . $client_id );
	}

	/**
	 * 案件詳細 URL を返す。
	 *
	 * @param int $order_id 案件 ID。
	 * @return string
	 */
	public function get_order_url( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '';
		}

		return $this->build_app_url( 'orders/' . $order_id );
	}

	/**
	 * 商品詳細 URL を返す。
	 *
	 * @param int $service_id 商品 ID。
	 * @return string
	 */
	public function get_service_url( int $service_id ): string {
		if ( $service_id <= 0 ) {
			return '';
		}

		return $this->build_app_url( 'services/' . $service_id );
	}

	/**
	 * API レスポンスから data 配列を取り出す。
	 *
	 * @param array<string, mixed> $response デコード済みレスポンス。
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_data_rows( array $response ): array {
		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		$rows = array();

		foreach ( $response['data'] as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * category クエリ（カンマ区切り可）を配列に変換する。
	 *
	 * @param string $category_attr カテゴリー文字列。
	 * @return array<int, string>
	 */
	private function parse_categories( string $category_attr ): array {
		if ( $category_attr === '' ) {
			return array();
		}

		$parts = preg_split( '/\s*,\s*/', $category_attr );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$categories = array();
		foreach ( $parts as $part ) {
			$category = sanitize_text_field( $part );
			if ( $category !== '' ) {
				$categories[] = $category;
			}
		}

		return array_values( array_unique( $categories ) );
	}
}
