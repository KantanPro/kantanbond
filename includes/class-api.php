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
			'Authorization' => 'Bearer ' . $this->settings->get_api_key(),
			'X-Api-Key'     => $this->settings->get_api_key(),
			'X-Api-Secret'  => $this->settings->get_api_secret(),
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
					$request_args['method'],
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
					$request_args['method'],
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
				$request_args['method'],
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
}
