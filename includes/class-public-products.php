<?php
/**
 * [kantanbond_public_products] ショートコードと admin-ajax プロキシ。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KantanBiz 公開商品 API 連携（インバウンドトークン・サーバー側プロキシ）。
 */
class KantanBond_Public_Products {

	public const NONCE_ACTION = 'kantanbond_public_product_order';

	public const AJAX_ACTION = 'kantanbond_public_product_submit';

	public const AJAX_PURCHASE_ACTION = 'kantanbond_public_product_purchase';

	/**
	 * @var bool
	 */
	private bool $stripe_available = false;

	/**
	 * @var KantanBond_API
	 */
	private KantanBond_API $api;

	/**
	 * @var KantanBond_Settings
	 */
	private KantanBond_Settings $settings;

	/**
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * @param KantanBond_API      $api      API クライアント。
	 * @param KantanBond_Settings $settings 設定。
	 */
	public function __construct( KantanBond_API $api, KantanBond_Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
	}

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'kantanbond_public_products', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_submit_order' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_submit_order' ) );
		add_action( 'wp_ajax_' . self::AJAX_PURCHASE_ACTION, array( $this, 'ajax_purchase_order' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PURCHASE_ACTION, array( $this, 'ajax_purchase_order' ) );
	}

	/**
	 * 公開商品ショートコードを描画する。
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_shortcode( array $atts = array() ): string {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'layout'        => 'grid',
				'columns'       => '3',
				'category'      => '',
				'ids'           => '',
				'limit'         => '0',
				'order_by'      => 'frequency',
				'order'         => 'ASC',
				'show_image'    => 'yes',
				'show_price'    => 'yes',
				'show_unit'     => 'yes',
				'show_category' => 'yes',
				'show_tax'      => 'no',
				'show_memo'     => 'yes',
				'show_initial_fees' => 'yes',
				'show_filter'   => 'yes',
				'align'         => 'left',
			),
			$atts,
			'kantanbond_public_products'
		);

		$selected_categories = $this->parse_categories( $atts['category'] );
		$result              = $this->api->get_public_products( $selected_categories );

		if ( is_wp_error( $result ) ) {
			return '<p class="kantanbond-public-products kantanbond-public-products--empty" role="alert">'
				. esc_html( $result->get_error_message() )
				. '</p>';
		}

		$products        = $result['products'];
		$all_categories  = $result['categories'];
		$this->stripe_available = ! empty( $result['stripe_available'] );
		$ids        = $this->parse_ids( $atts['ids'] );

		if ( $ids !== array() ) {
			$products = array_values(
				array_filter(
					$products,
					static function ( array $row ) use ( $ids ): bool {
						$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

						return in_array( $id, $ids, true );
					}
				)
			);
		}

		$products = $this->sort_products( $products, sanitize_key( $atts['order_by'] ), sanitize_key( $atts['order'] ) );

		$limit = max( 0, (int) $atts['limit'] );
		if ( $limit > 0 ) {
			$products = array_slice( $products, 0, $limit );
		}

		if ( $products === array() ) {
			return '<p class="kantanbond-public-products kantanbond-public-products--empty">'
				. esc_html__( '公開中の商品がありません。', 'kantanbond' )
				. '</p>';
		}

		$layout = sanitize_key( $atts['layout'] );
		if ( $layout === 'grit' ) {
			$layout = 'grid';
		}
		if ( ! in_array( $layout, array( 'grid', 'table', 'cards' ), true ) ) {
			$layout = 'grid';
		}

		$columns = max( 1, min( 4, (int) $atts['columns'] ) );
		$display = array(
			'image'    => $this->is_flag_enabled( $atts['show_image'] ),
			'price'    => $this->is_flag_enabled( $atts['show_price'] ),
			'unit'     => $this->is_flag_enabled( $atts['show_unit'] ),
			'category' => $this->is_flag_enabled( $atts['show_category'] ),
			'tax'      => $this->is_flag_enabled( $atts['show_tax'] ),
			'memo'     => $this->is_flag_enabled( $atts['show_memo'] ),
			'initial_fees' => $this->is_flag_enabled( $atts['show_initial_fees'] ),
		);

		if ( $layout === 'table' ) {
			$inner = $this->render_table( $products, $display );
		} elseif ( $layout === 'cards' ) {
			$inner = $this->render_cards( $products, $display, $columns );
		} else {
			$inner = $this->render_grid( $products, $display, $columns );
		}

		$show_filter       = $this->is_flag_enabled( $atts['show_filter'] );
		$filter_categories = $selected_categories !== array() ? $selected_categories : $all_categories;
		$filter_initial    = count( $selected_categories ) === 1 ? $selected_categories[0] : '';
		$filter_html       = ( $show_filter && $filter_categories !== array() )
			? $this->render_category_filter( $filter_categories, $filter_initial )
			: '';

		$this->enqueue_assets();

		$wrapper_class = KantanBond_Shortcode_Align::merge_classes(
			'kantanbond-public-products kantanbond-public-products--' . $layout,
			(string) $atts['align'],
			'kantanbond-public-products'
		);

		return '<div class="' . esc_attr( $wrapper_class ) . '">'
			. $filter_html
			. '<div class="kantanbond-public-products-list">'
			. $inner
			. '<p class="kantanbond-public-products-filter__empty" hidden>'
			. esc_html__( '該当する商品がありません。', 'kantanbond' )
			. '</p>'
			. '</div>'
			. $this->render_detail_shell()
			. $this->render_image_lightbox_shell()
			. '</div>';
	}

	/**
	 * お申し込み AJAX（SaaS API へプロキシ）。
	 *
	 * @return void
	 */
	public function ajax_submit_order(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! empty( $_POST['company_url'] ) ) {
			wp_send_json_error(
				array( 'message' => __( '送信に失敗しました。', 'kantanbond' ) ),
				400
			);
		}

		$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;
		if ( $service_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( '商品が指定されていません。', 'kantanbond' ) ),
				400
			);
		}

		$payload = array(
			'service_id'   => $service_id,
			'company_name' => isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['company_name'] ) ) : '',
			'contact_name' => isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['contact_name'] ) ) : '',
			'email'        => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '',
			'phone'        => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '',
			'message'      => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '',
			'quantity'     => isset( $_POST['quantity'] ) ? (float) wp_unslash( $_POST['quantity'] ) : 1,
		);

		if ( $payload['contact_name'] === '' ) {
			wp_send_json_error(
				array( 'message' => __( 'お名前を入力してください。', 'kantanbond' ) ),
				400
			);
		}

		if ( $payload['email'] === '' || ! is_email( $payload['email'] ) ) {
			wp_send_json_error(
				array( 'message' => __( '有効なメールアドレスを入力してください。', 'kantanbond' ) ),
				400
			);
		}

		if ( $payload['quantity'] < 1 ) {
			$payload['quantity'] = 1;
		}

		$response = $this->api->submit_public_product_order( $payload );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array( 'message' => $response->get_error_message() ),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'  => __( 'お申し込みを受け付けました。担当者よりご連絡いたします。', 'kantanbond' ),
				'order_id' => isset( $response['data']['order_id'] ) ? (int) $response['data']['order_id'] : 0,
			)
		);
	}

	/**
	 * Stripe 即時購入 AJAX（SaaS API へプロキシ）。
	 *
	 * @return void
	 */
	public function ajax_purchase_order(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! empty( $_POST['company_url'] ) ) {
			wp_send_json_error(
				array( 'message' => __( '送信に失敗しました。', 'kantanbond' ) ),
				400
			);
		}

		$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;
		if ( $service_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( '商品が指定されていません。', 'kantanbond' ) ),
				400
			);
		}

		$return_url = isset( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['return_url'] ) ) : '';
		if ( $return_url === '' ) {
			$return_url = wp_get_referer() ?: home_url( '/' );
		}

		$payload = array(
			'service_id'   => $service_id,
			'company_name' => isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['company_name'] ) ) : '',
			'contact_name' => isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['contact_name'] ) ) : '',
			'email'        => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '',
			'phone'        => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '',
			'message'      => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '',
			'quantity'     => isset( $_POST['quantity'] ) ? (float) wp_unslash( $_POST['quantity'] ) : 1,
			'success_url'  => KantanBond_Public_Purchase_Thank_You::get_success_url(),
			'cancel_url'   => KantanBond_Public_Purchase_Thank_You::get_cancel_url( $return_url ),
			'return_url'   => $return_url,
		);

		if ( $payload['contact_name'] === '' ) {
			wp_send_json_error(
				array( 'message' => __( 'お名前を入力してください。', 'kantanbond' ) ),
				400
			);
		}

		if ( $payload['email'] === '' || ! is_email( $payload['email'] ) ) {
			wp_send_json_error(
				array( 'message' => __( '有効なメールアドレスを入力してください。', 'kantanbond' ) ),
				400
			);
		}

		if ( $payload['quantity'] < 1 ) {
			$payload['quantity'] = 1;
		}

		$response = $this->api->submit_public_product_purchase( $payload );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array( 'message' => $response->get_error_message() ),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'      => __( '決済ページへ移動します…', 'kantanbond' ),
				'order_id'     => isset( $response['data']['order_id'] ) ? (int) $response['data']['order_id'] : 0,
				'checkout_url' => isset( $response['data']['checkout_url'] ) ? (string) $response['data']['checkout_url'] : '',
			)
		);
	}

	/**
	 * CSS/JS を読み込む。
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		self::$assets_enqueued = true;

		wp_enqueue_style(
			'kantanbond-public-products',
			KANTANBOND_PLUGIN_URL . 'assets/css/public-products.css',
			array(),
			KANTANBOND_VERSION
		);

		$card_bg_color = $this->settings->get_public_product_card_bg_color();
		if ( $card_bg_color !== '' ) {
			$inline_css = '.kantanbond-public-products { --kantanbond-public-product-card-bg: ' . esc_attr( $card_bg_color ) . '; }';
			wp_add_inline_style( 'kantanbond-public-products', $inline_css );
		}

		wp_enqueue_script(
			'kantanbond-public-products',
			KANTANBOND_PLUGIN_URL . 'assets/js/public-products.js',
			array(),
			KANTANBOND_VERSION,
			true
		);

		wp_localize_script(
			'kantanbond-public-products',
			'kantanbondPublicProducts',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'stripeAvailable' => $this->stripe_available,
				'i18n'    => array(
					'orderTitle'        => __( 'お問い合わせ', 'kantanbond' ),
					'orderTitlePurchase'=> __( 'ご購入', 'kantanbond' ),
					'submit'            => __( '送信する', 'kantanbond' ),
					'submitPurchase'    => __( '購入する', 'kantanbond' ),
					'submitInquire'     => __( '送信する', 'kantanbond' ),
					'submitting'        => __( '送信中…', 'kantanbond' ),
					'submittingPurchase'=> __( '決済準備中…', 'kantanbond' ),
					'close'             => __( '閉じる', 'kantanbond' ),
					'category'          => __( 'カテゴリ', 'kantanbond' ),
					'price'             => __( '単価', 'kantanbond' ),
					'unit'              => __( '単位', 'kantanbond' ),
					'tax'               => __( '税率', 'kantanbond' ),
					'memo'              => __( 'メモ', 'kantanbond' ),
					'initialFees'       => __( '初回費用', 'kantanbond' ),
					'initialFeesNote'   => __( '初回請求時のみ', 'kantanbond' ),
					'recurringBadge'    => __( '定期', 'kantanbond' ),
					'quantity'          => __( '数量', 'kantanbond' ),
					'companyName'       => __( '会社名', 'kantanbond' ),
					'contactName'       => __( 'お名前', 'kantanbond' ),
					'email'             => __( 'メールアドレス', 'kantanbond' ),
					'phone'             => __( '電話番号', 'kantanbond' ),
					'message'           => __( 'ご要望・備考', 'kantanbond' ),
					'requiredMark'      => __( '必須', 'kantanbond' ),
					'networkError'      => __( '通信エラーが発生しました。時間をおいて再度お試しください。', 'kantanbond' ),
					'filterLabel'       => __( 'カテゴリで絞り込み', 'kantanbond' ),
					'filterPlaceholder' => __( 'カテゴリを入力または選択…', 'kantanbond' ),
					'filterAll'         => __( 'すべて表示', 'kantanbond' ),
					'filterEmpty'       => __( '該当する商品がありません。', 'kantanbond' ),
					'enlargeImage'      => __( '画像を拡大', 'kantanbond' ),
					'pendingBadge'      => __( '保留中', 'kantanbond' ),
					'soldOutBadge'      => __( '完売御礼！', 'kantanbond' ),
					'pendingNotice'     => __( '現在お問い合わせを受け付けておりません。', 'kantanbond' ),
					'soldOutNotice'     => __( 'こちらの商品は完売しました。', 'kantanbond' ),
					'inquire'           => __( '問い合わす', 'kantanbond' ),
					'purchase'          => __( '購入する', 'kantanbond' ),
					'sessionExpired'    => __( 'セッションの有効期限が切れました。ページを再読み込みして再度お試しください。', 'kantanbond' ),
				),
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $products 商品一覧。
	 * @param array<string, bool>             $display  表示フラグ。
	 * @return string
	 */
	private function render_grid( array $products, array $display, int $columns ): string {
		$items = '';

		foreach ( $products as $product ) {
			$row     = $this->format_product_row( $product );
			$payload = $row;

			$image_html = '';
			if ( $display['image'] ) {
				$show_overlay = empty( $row['acceptance_open'] );
				$status_label = (string) ( $row['status_label'] ?? '' );
				if ( $row['image'] !== '' ) {
					$image_html = $this->render_product_image_html(
						$row['image'],
						$row['name'],
						'kantanbond-public-products-grid__image',
						'kantanbond-public-products-grid__image-wrap',
						'',
						$show_overlay,
						$status_label
					);
				} elseif ( $show_overlay && $status_label !== '' ) {
					$image_html = '<div class="kantanbond-public-products-grid__image-wrap kantanbond-public-product-item__image-wrap--pending">'
						. $this->render_product_status_overlay_html( $status_label )
						. '</div>';
				}
			}

			$category_html = ( $display['category'] && $row['category'] !== '' )
				? '<p class="kantanbond-public-products-grid__category">' . esc_html( $row['category'] ) . '</p>'
				: '';

			$price_block = $this->render_product_list_price_block_html( $row, $display, 'kantanbond-public-products-grid' );

			$memo_html = $display['memo']
				? $this->render_product_list_memo_html( $row['memo'], 'kantanbond-public-products-grid__memo' )
				: '';

			$initial_fees_html = $display['initial_fees']
				? $this->render_product_list_initial_fees_html( $row, 'kantanbond-public-products-grid__initial-fees' )
				: '';

			$public_html_block = $this->render_product_public_html_block(
				(string) ( $row['public_html'] ?? '' ),
				'kantanbond-public-products-grid__public-html'
			);

			$inquire_html = $this->render_inquiry_button_html( $row );

			$items .= '<article' . $this->item_attrs( $payload, 'kantanbond-public-products-grid__item' ) . '>'
				. $image_html
				. '<div class="kantanbond-public-products-grid__body">'
				. '<h3 class="kantanbond-public-products-grid__name">' . esc_html( $row['name'] ) . '</h3>'
				. $category_html
				. $price_block
				. $initial_fees_html
				. $memo_html
				. $public_html_block
				. '</div>'
				. $inquire_html
				. '</article>';
		}

		return '<div class="kantanbond-public-products-grid kantanbond-public-products-grid--cols-' . esc_attr( (string) $columns ) . '">' . $items . '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $products 商品一覧。
	 * @param array<string, bool>             $display  表示フラグ。
	 * @return string
	 */
	private function render_cards( array $products, array $display, int $columns ): string {
		$items = '';

		foreach ( $products as $product ) {
			$row     = $this->format_product_row( $product );
			$payload = $row;

			$image_html = '';
			if ( $display['image'] ) {
				$show_overlay = empty( $row['acceptance_open'] );
				$status_label = (string) ( $row['status_label'] ?? '' );
				if ( $row['image'] !== '' ) {
					$image_html = $this->render_product_image_html(
						$row['image'],
						$row['name'],
						'kantanbond-public-products-card__image',
						'kantanbond-public-products-card__image-wrap',
						'',
						$show_overlay,
						$status_label
					);
				} elseif ( $show_overlay && $status_label !== '' ) {
					$image_html = '<div class="kantanbond-public-products-card__image-wrap kantanbond-public-product-item__image-wrap--pending">'
						. $this->render_product_status_overlay_html( $status_label )
						. '</div>';
				}
			}

			$category_html = ( $display['category'] && $row['category'] !== '' )
				? '<p class="kantanbond-public-products-card__category">' . esc_html( $row['category'] ) . '</p>'
				: '';

			$price_block = $this->render_product_list_price_block_html( $row, $display, 'kantanbond-public-products-card' );

			$memo_html = $display['memo']
				? $this->render_product_list_memo_html( $row['memo'], 'kantanbond-public-products-card__memo' )
				: '';

			$initial_fees_html = $display['initial_fees']
				? $this->render_product_list_initial_fees_html( $row, 'kantanbond-public-products-card__initial-fees' )
				: '';

			$public_html_block = $this->render_product_public_html_block(
				(string) ( $row['public_html'] ?? '' ),
				'kantanbond-public-products-card__public-html'
			);

			$inquire_html = $this->render_inquiry_button_html( $row );

			$items .= '<article' . $this->item_attrs( $payload, 'kantanbond-public-products-card' ) . '>'
				. $image_html
				. '<div class="kantanbond-public-products-card__body">'
				. $category_html
				. '<h3 class="kantanbond-public-products-card__name">' . esc_html( $row['name'] ) . '</h3>'
				. $price_block
				. $initial_fees_html
				. $memo_html
				. $public_html_block
				. '</div>'
				. $inquire_html
				. '</article>';
		}

		return '<div class="kantanbond-public-products-cards kantanbond-public-products-cards--cols-' . esc_attr( (string) $columns ) . '">' . $items . '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $products 商品一覧。
	 * @param array<string, bool>             $display  表示フラグ。
	 * @return string
	 */
	private function render_table( array $products, array $display ): string {
		$headers = array();
		$rows    = '';

		if ( $display['image'] ) {
			$headers[] = '<th scope="col">' . esc_html__( '画像', 'kantanbond' ) . '</th>';
		}
		$headers[] = '<th scope="col">' . esc_html__( '商品名', 'kantanbond' ) . '</th>';
		if ( $display['category'] ) {
			$headers[] = '<th scope="col">' . esc_html__( 'カテゴリ', 'kantanbond' ) . '</th>';
		}
		if ( $display['price'] ) {
			$headers[] = '<th scope="col">' . esc_html__( '単価', 'kantanbond' ) . '</th>';
		}
		if ( $display['unit'] ) {
			$headers[] = '<th scope="col">' . esc_html__( '単位', 'kantanbond' ) . '</th>';
		}
		if ( $display['tax'] ) {
			$headers[] = '<th scope="col">' . esc_html__( '税率（%）', 'kantanbond' ) . '</th>';
		}
		if ( $display['memo'] ) {
			$headers[] = '<th scope="col">' . esc_html__( 'メモ', 'kantanbond' ) . '</th>';
		}
		if ( $display['initial_fees'] ) {
			$headers[] = '<th scope="col">' . esc_html__( '初回費用', 'kantanbond' ) . '</th>';
		}
		$headers[] = '<th scope="col">' . esc_html__( 'お問い合わせ', 'kantanbond' ) . '</th>';

		foreach ( $products as $product ) {
			$row     = $this->format_product_row( $product );
			$payload = $row;
			$cells   = array();

			if ( $display['image'] ) {
				$show_overlay = empty( $row['acceptance_open'] );
				$status_label = (string) ( $row['status_label'] ?? '' );
				$img          = $row['image'] !== ''
					? $this->render_product_image_html(
						$row['image'],
						$row['name'],
						'kantanbond-public-products-thumb',
						'kantanbond-public-products-table__image-wrap',
						'width="48" height="48"',
						$show_overlay,
						$status_label
					)
					: '';
				$cells[] = '<td>' . $img . '</td>';
			}
			$cells[] = '<td>' . esc_html( $row['name'] ) . '</td>';
			if ( $display['category'] ) {
				$cells[] = '<td>' . esc_html( $row['category'] ) . '</td>';
			}
			if ( $display['price'] ) {
				$price_cell = ! empty( $row['recurring_items_summary'] )
					? (string) $row['recurring_items_summary']
					: (string) $row['price_display'];
				$cells[] = '<td>' . esc_html( $price_cell ) . '</td>';
			}
			if ( $display['unit'] ) {
				$cells[] = '<td>' . esc_html( $row['unit'] ) . '</td>';
			}
			if ( $display['tax'] ) {
				$cells[] = '<td>' . esc_html( $row['tax_rate'] ) . '</td>';
			}
			if ( $display['memo'] ) {
				$cells[] = '<td class="kantanbond-public-products-table__memo">' . esc_html( $row['memo'] ) . '</td>';
			}
			if ( $display['initial_fees'] ) {
				$cells[] = '<td class="kantanbond-public-products-table__initial-fees">' . esc_html( $row['initial_fees_summary'] ) . '</td>';
			}

			$cells[] = '<td class="kantanbond-public-products-table__inquire">' . $this->render_inquiry_button_html( $row, true ) . '</td>';

			$rows .= '<tr' . $this->item_attrs( $payload ) . '>' . implode( '', $cells ) . '</tr>';
		}

		return '<table class="kantanbond-public-products-table"><thead><tr>'
			. implode( '', $headers )
			. '</tr></thead><tbody>'
			. $rows
			. '</tbody></table>';
	}

	/**
	 * @param array<int, string> $categories カテゴリ一覧。
	 * @param string             $initial    初期値。
	 * @return string
	 */
	private function render_category_filter( array $categories, string $initial = '' ): string {
		$list_id = 'kantanbond-public-products-categories-' . wp_rand( 1000, 9999 );
		$options = '';

		foreach ( $categories as $cat ) {
			$options .= '<option value="' . esc_attr( $cat ) . '"></option>';
		}

		$initial_attr = $initial !== '' ? ' value="' . esc_attr( $initial ) . '"' : '';

		return '<div class="kantanbond-public-products-filter">'
			. '<label class="kantanbond-public-products-filter__label" for="' . esc_attr( $list_id ) . '-input">'
			. esc_html__( 'カテゴリで絞り込み', 'kantanbond' )
			. '</label>'
			. '<input type="search" class="kantanbond-public-products-filter__input" id="' . esc_attr( $list_id ) . '-input" list="' . esc_attr( $list_id ) . '"'
			. ' placeholder="' . esc_attr__( 'カテゴリを入力または選択…', 'kantanbond' ) . '" autocomplete="off"' . $initial_attr . ' />'
			. '<datalist id="' . esc_attr( $list_id ) . '">' . $options . '</datalist>'
			. '<button type="button" class="kantanbond-public-products-filter__clear">' . esc_html__( 'すべて表示', 'kantanbond' ) . '</button>'
			. '</div>';
	}

	/**
	 * モーダル・お申し込みフォームのシェル。
	 *
	 * @return string
	 */
	private function render_detail_shell(): string {
		ob_start();
		?>
		<div class="kantanbond-public-product-detail" id="kantanbond-public-product-detail" hidden>
			<button type="button" class="kantanbond-public-product-detail__backdrop" aria-label="<?php echo esc_attr__( '閉じる', 'kantanbond' ); ?>"></button>
			<div class="kantanbond-public-product-detail__frame">
				<div class="kantanbond-public-product-detail__toolbar">
					<button type="button" class="kantanbond-public-product-detail__close" aria-label="<?php echo esc_attr__( '閉じる', 'kantanbond' ); ?>">
						<span class="kantanbond-public-product-detail__close-icon" aria-hidden="true">&times;</span>
						<span class="kantanbond-public-product-detail__close-text"><?php echo esc_html__( '閉じる', 'kantanbond' ); ?></span>
					</button>
				</div>
				<div class="kantanbond-public-product-detail__panel" role="dialog" aria-modal="true" aria-labelledby="kantanbond-public-product-detail-title">
				<div class="kantanbond-public-product-detail__header">
					<span class="kantanbond-public-product-detail__header-title"><?php echo esc_html__( '商品詳細', 'kantanbond' ); ?></span>
				</div>
				<div class="kantanbond-public-product-detail__body">
				<div class="kantanbond-public-product-detail__content"></div>
				<form class="kantanbond-public-product-order-form" novalidate>
					<h4 class="kantanbond-public-product-order-form__title" id="kantanbond-public-product-detail-title"><?php echo esc_html__( 'お問い合わせ', 'kantanbond' ); ?></h4>
					<input type="hidden" name="service_id" value="" />
					<p class="kantanbond-public-product-order-form__field">
						<label for="kantanbond-pp-company"><?php echo esc_html__( '会社名', 'kantanbond' ); ?></label>
						<input type="text" id="kantanbond-pp-company" name="company_name" autocomplete="organization" />
					</p>
					<p class="kantanbond-public-product-order-form__field">
						<label for="kantanbond-pp-contact"><?php echo esc_html__( 'お名前', 'kantanbond' ); ?> <span class="required">*</span></label>
						<input type="text" id="kantanbond-pp-contact" name="contact_name" required autocomplete="name" />
					</p>
					<p class="kantanbond-public-product-order-form__field">
						<label for="kantanbond-pp-email"><?php echo esc_html__( 'メールアドレス', 'kantanbond' ); ?> <span class="required">*</span></label>
						<input type="email" id="kantanbond-pp-email" name="email" required autocomplete="email" />
					</p>
					<p class="kantanbond-public-product-order-form__field">
						<label for="kantanbond-pp-phone"><?php echo esc_html__( '電話番号', 'kantanbond' ); ?></label>
						<input type="tel" id="kantanbond-pp-phone" name="phone" autocomplete="tel" />
					</p>
					<p class="kantanbond-public-product-order-form__field kantanbond-public-product-order-form__field--quantity">
						<label for="kantanbond-pp-quantity"><?php echo esc_html__( '数量', 'kantanbond' ); ?></label>
						<span class="kantanbond-public-product-order-form__quantity-control">
							<input type="number" id="kantanbond-pp-quantity" name="quantity" min="1" step="1" value="1" inputmode="numeric" />
							<span class="kantanbond-public-product-order-form__quantity-unit" hidden></span>
						</span>
					</p>
					<p class="kantanbond-public-product-order-form__field">
						<label for="kantanbond-pp-message"><?php echo esc_html__( 'ご要望・備考', 'kantanbond' ); ?></label>
						<textarea id="kantanbond-pp-message" name="message" rows="4"></textarea>
					</p>
					<p class="kantanbond-public-product-order-form__honeypot" aria-hidden="true">
						<label for="kantanbond-pp-company-url">URL</label>
						<input type="text" id="kantanbond-pp-company-url" name="company_url" tabindex="-1" autocomplete="off" />
					</p>
					<p class="kantanbond-public-product-order-form__actions">
						<button type="submit" class="kantanbond-public-product-order-form__submit"><?php echo esc_html__( '送信する', 'kantanbond' ); ?></button>
						<button type="button" class="kantanbond-public-product-order-form__close"><?php echo esc_html__( '閉じる', 'kantanbond' ); ?></button>
					</p>
					<div class="kantanbond-public-product-order-form__message" role="status" aria-live="polite" hidden></div>
				</form>
				</div>
			</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $product API 商品行。
	 * @return array<string, mixed>
	 */
	private function format_product_row( array $product ): array {
		$name     = isset( $product['name'] ) ? (string) $product['name'] : '';
		$price    = isset( $product['price'] ) ? (float) $product['price'] : 0.0;
		$unit     = isset( $product['unit'] ) ? (string) $product['unit'] : '';
		$category = isset( $product['category'] ) ? (string) $product['category'] : '';
		$tax_rate = isset( $product['tax_rate'] ) && $product['tax_rate'] !== null && $product['tax_rate'] !== ''
			? (string) $product['tax_rate']
			: '';
		$memo     = isset( $product['memo'] ) ? trim( (string) $product['memo'] ) : '';
		$initial_fees = $this->normalize_initial_fees( $product['initial_fees'] ?? array() );
		$recurring_items = $this->normalize_recurring_items( $product['recurring_items'] ?? array() );
		$is_recurring = ! empty( $product['is_recurring'] );

		$image = '';
		if ( isset( $product['image_url'] ) && is_string( $product['image_url'] ) && $product['image_url'] !== '' ) {
			$image = $this->api->resolve_asset_url( $product['image_url'] );
		}

		$acceptance_open    = ! isset( $product['acceptance_open'] ) || (bool) $product['acceptance_open'];
		$availability_state = isset( $product['availability_state'] ) ? (string) $product['availability_state'] : 'open';
		$status_label       = isset( $product['status_label'] ) ? (string) $product['status_label'] : '';
		$is_sold_out        = ! empty( $product['is_sold_out'] ) || $availability_state === 'sold_out';
		$is_pending         = ! empty( $product['is_pending'] ) || $availability_state === 'pending';
		$status_label       = $this->resolve_availability_label( $status_label, $availability_state, $is_sold_out, $is_pending );

		return array(
			'id'            => isset( $product['id'] ) ? (int) $product['id'] : 0,
			'name'          => $name,
			'price'         => $price,
			'price_display' => $this->format_yen( $price ),
			'unit'          => $unit,
			'category'      => $category,
			'tax_rate'      => $tax_rate,
			'memo'          => $memo,
			'is_recurring'  => $is_recurring,
			'initial_fees'  => $initial_fees,
			'recurring_items' => $recurring_items,
			'initial_fees_summary' => $this->format_initial_fees_summary( $initial_fees ),
			'recurring_items_summary' => $this->format_recurring_items_summary( $recurring_items, $unit ),
			'image'         => $image,
			'acceptance_open' => $acceptance_open,
			'availability_state' => $availability_state,
			'is_sold_out'   => $is_sold_out,
			'is_pending'    => $is_pending,
			'status_label'  => $status_label,
			'quantity_fixed' => ! empty( $product['quantity_fixed'] ),
			'instant_purchase' => ! empty( $product['instant_purchase'] ),
			'public_html'    => isset( $product['public_html'] ) ? trim( (string) $product['public_html'] ) : '',
		);
	}

	/**
	 * @param mixed $raw API の recurring_items。
	 * @return array<int, array{item_name: string, amount: float, amount_display: string, tax_rate: string}>
	 */
	private function normalize_recurring_items( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['item_name'] ) ? trim( (string) $row['item_name'] ) : '';
			if ( $name === '' ) {
				continue;
			}
			$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
			$tax_rate = isset( $row['tax_rate'] ) && $row['tax_rate'] !== null && $row['tax_rate'] !== ''
				? (string) $row['tax_rate']
				: '';

			$items[] = array(
				'item_name'      => $name,
				'amount'         => $amount,
				'amount_display' => $this->format_yen( $amount ),
				'tax_rate'       => $tax_rate,
			);
		}

		return $items;
	}

	/**
	 * @param array<int, array{item_name: string, amount_display: string}> $recurring_items 定期請求項目。
	 * @param string                                                        $unit            単位。
	 * @return string
	 */
	private function format_recurring_items_summary( array $recurring_items, string $unit ): string {
		if ( $recurring_items === array() ) {
			return '';
		}

		$parts = array();
		foreach ( $recurring_items as $item ) {
			$parts[] = $this->format_recurring_item_line(
				(string) $item['item_name'],
				(string) $item['amount_display'],
				$unit,
			);
		}

		return implode( '、', $parts );
	}

	private function format_recurring_item_line( string $item_name, string $amount_display, string $unit ): string {
		$suffix = $unit !== '' ? '/' . $unit : '';

		return $item_name . ' ' . $amount_display . $suffix;
	}

	/**
	 * @param array<string, mixed> $row     整形済み商品行。
	 * @param array<string, bool> $display 表示フラグ。
	 * @param string               $prefix  CSS クラス接頭辞（grid / card）。
	 * @return string
	 */
	private function render_product_list_price_block_html( array $row, array $display, string $prefix ): string {
		if ( ! $display['price'] && ! ( $display['unit'] && $row['unit'] !== '' ) && ! ( $display['tax'] && $row['tax_rate'] !== '' ) ) {
			return '';
		}

		$unit = (string) ( $row['unit'] ?? '' );
		$service_tax_rate = (string) ( $row['tax_rate'] ?? '' );
		$price_html = '';
		$has_recurring_items = ! empty( $row['recurring_items'] ) && is_array( $row['recurring_items'] );

		if ( $has_recurring_items ) {
			$lines = '';
			foreach ( $row['recurring_items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$item_name = (string) ( $item['item_name'] ?? '' );
				$amount_display = (string) ( $item['amount_display'] ?? '' );
				if ( $item_name === '' || $amount_display === '' ) {
					continue;
				}
				$line_text = $this->format_recurring_item_line( $item_name, $amount_display, $display['unit'] && $unit !== '' ? $unit : '' );
				$lines .= '<div class="' . esc_attr( $prefix ) . '__recurring-item">'
					. '<span class="' . esc_attr( $prefix ) . '__recurring-item-line">' . esc_html( $line_text ) . '</span>'
					. '</div>';
			}
			if ( $lines !== '' ) {
				$price_html = '<div class="' . esc_attr( $prefix ) . '__recurring-items">' . $lines . '</div>';
			}
		}

		if ( $price_html === '' && $display['price'] ) {
			$price_part = '<span class="' . esc_attr( $prefix ) . '__price">' . esc_html( (string) $row['price_display'] ) . '</span>';
			$unit_part = ( $display['unit'] && $unit !== '' )
				? '<span class="' . esc_attr( $prefix ) . '__unit">/' . esc_html( $unit ) . '</span>'
				: '';
			$price_html = '<div class="' . esc_attr( $prefix ) . '__price-row">' . $price_part . $unit_part . '</div>';
		}

		$tax_html = ( $display['tax'] && $service_tax_rate !== '' && ! $has_recurring_items )
			? '<span class="' . esc_attr( $prefix ) . '__tax">' . esc_html__( '税率', 'kantanbond' ) . ': ' . esc_html( $service_tax_rate ) . '%</span>'
			: '';

		if ( $price_html === '' && $tax_html === '' ) {
			return '';
		}

		return '<div class="' . esc_attr( $prefix ) . '__price-block">' . $price_html . $tax_html . '</div>';
	}

	/**
	 * @param mixed $raw API の initial_fees。
	 * @return array<int, array{fee_name: string, amount: float, amount_display: string, tax_rate: string}>
	 */
	private function normalize_initial_fees( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$fees = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['fee_name'] ) ? trim( (string) $row['fee_name'] ) : '';
			if ( $name === '' ) {
				continue;
			}
			$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
			$tax_rate = isset( $row['tax_rate'] ) && $row['tax_rate'] !== null && $row['tax_rate'] !== ''
				? (string) $row['tax_rate']
				: '';

			$fees[] = array(
				'fee_name'       => $name,
				'amount'         => $amount,
				'amount_display' => $this->format_yen( $amount ),
				'tax_rate'       => $tax_rate,
			);
		}

		return $fees;
	}

	/**
	 * @param array<int, array{fee_name: string, amount_display: string}> $initial_fees 初回費用。
	 * @return string
	 */
	private function format_initial_fees_summary( array $initial_fees ): string {
		if ( $initial_fees === array() ) {
			return '';
		}

		$parts = array();
		foreach ( $initial_fees as $fee ) {
			$parts[] = $fee['fee_name'] . ' ' . $fee['amount_display'];
		}

		return implode( '、', $parts );
	}

	/**
	 * @param array<string, mixed> $row 整形済み商品行。
	 * @param string               $css_class CSS クラス名。
	 * @return string
	 */
	private function render_product_list_initial_fees_html( array $row, string $css_class ): string {
		if ( empty( $row['initial_fees'] ) || ! is_array( $row['initial_fees'] ) ) {
			return '';
		}

		$fee_lines = array();
		foreach ( $row['initial_fees'] as $fee ) {
			if ( ! is_array( $fee ) ) {
				continue;
			}
			$fee_lines[] = esc_html( (string) ( $fee['fee_name'] ?? '' ) ) . ' '
				. esc_html( (string) ( $fee['amount_display'] ?? '' ) );
		}

		if ( $fee_lines === array() ) {
			return '';
		}

		$items = '';
		$last  = count( $fee_lines ) - 1;
		foreach ( $fee_lines as $index => $line ) {
			$items .= '<span class="' . esc_attr( $css_class ) . '__item">' . $line;
			if ( $index === $last ) {
				$items .= '<span class="' . esc_attr( $css_class ) . '__note">' . esc_html__( '初回請求時のみ', 'kantanbond' ) . '</span>';
			}
			$items .= '</span>';
		}

		return '<div class="' . esc_attr( $css_class ) . '">'
			. '<span class="' . esc_attr( $css_class ) . '__label">' . esc_html__( '初回費用', 'kantanbond' ) . '</span>'
			. $items
			. '</div>';
	}

	/**
	 * 一覧ブロック内の商品画像 HTML（クリックで拡大表示）。
	 *
	 * @param string $image_url   画像 URL。
	 * @param string $name        商品名（alt・aria-label 用）。
	 * @param string $image_class 画像要素の CSS クラス。
	 * @param string $wrap_class  ラップ要素の CSS クラス（空なら省略）。
	 * @param string $extra_attrs img 要素の追加属性（例: width/height）。
	 * @param bool   $show_status_overlay 受付停止オーバーレイを画像上に表示するか。
	 * @param string $status_label        オーバーレイ文言。
	 * @return string
	 */
	private function render_product_image_html( string $image_url, string $name, string $image_class, string $wrap_class = '', string $extra_attrs = '', bool $show_status_overlay = false, string $status_label = '' ): string {
		$label = sprintf(
			/* translators: %s: product name */
			__( '%s の画像を拡大', 'kantanbond' ),
			$name
		);

		$image_markup = '<button type="button" class="kantanbond-public-product-item__image-btn" aria-label="' . esc_attr( $label ) . '">'
			. '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $name ) . '" class="' . esc_attr( trim( $image_class . ' kantanbond-public-product-item__image' ) ) . '" loading="lazy" decoding="async"'
			. ( $extra_attrs !== '' ? ' ' . $extra_attrs : '' )
			. ' /></button>';

		if ( $wrap_class === '' ) {
			return $image_markup;
		}

		$wrap_classes = trim( $wrap_class . ( $show_status_overlay ? ' kantanbond-public-product-item__image-wrap--pending' : '' ) );
		$overlay      = $show_status_overlay ? $this->render_product_status_overlay_html( $status_label ) : '';

		return '<div class="' . esc_attr( $wrap_classes ) . '">' . $image_markup . $overlay . '</div>';
	}

	/**
	 * 画像上に重ねる受付停止オーバーレイ HTML。
	 *
	 * @param string $label 表示ラベル（保留中 / 完売御礼！ 等）。
	 * @return string
	 */
	private function render_product_status_overlay_html( string $label ): string {
		$label = trim( $label );
		if ( $label === '' ) {
			return '';
		}

		return '<span class="kantanbond-public-product-item__pending-overlay" aria-hidden="true">'
			. '<span class="kantanbond-public-product-item__pending-overlay-badge">' . esc_html( $label ) . '</span>'
			. '</span>';
	}

	/**
	 * 画像拡大表示用ライトボックスの HTML を返す。
	 *
	 * @return string
	 */
	private function render_image_lightbox_shell(): string {
		ob_start();
		?>
		<div class="kantanbond-public-product-image-lightbox" id="kantanbond-public-product-image-lightbox" hidden>
			<button type="button" class="kantanbond-public-product-image-lightbox__backdrop" aria-label="<?php echo esc_attr__( '閉じる', 'kantanbond' ); ?>"></button>
			<div class="kantanbond-public-product-image-lightbox__frame">
				<div class="kantanbond-public-product-image-lightbox__toolbar">
					<button type="button" class="kantanbond-public-product-image-lightbox__close" aria-label="<?php echo esc_attr__( '閉じる', 'kantanbond' ); ?>">&times;</button>
				</div>
				<figure class="kantanbond-public-product-image-lightbox__figure">
					<img class="kantanbond-public-product-image-lightbox__image" alt="" decoding="async" />
					<figcaption class="kantanbond-public-product-image-lightbox__caption"></figcaption>
				</figure>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * 一覧レイアウト用のメモ HTML を返す。
	 *
	 * @param string $memo      メモ本文。
	 * @param string $css_class CSS クラス名。
	 * @return string
	 */
	private function render_product_list_memo_html( string $memo, string $css_class ): string {
		if ( $memo === '' ) {
			return '';
		}

		return '<p class="' . esc_attr( $css_class ) . '">' . esc_html( $memo ) . '</p>';
	}

	/**
	 * 公開用 HTML ブロック（API 側でサニタイズ済み）。
	 *
	 * @param string $public_html 表示用 HTML。
	 * @param string $css_class   CSS クラス。
	 * @return string
	 */
	private function render_product_public_html_block( string $public_html, string $css_class ): string {
		if ( $public_html === '' ) {
			return '';
		}

		return '<div class="' . esc_attr( $css_class ) . '">' . $public_html . '</div>';
	}

	/**
	 * 一覧ブロック下部の「問い合わす」ボタン HTML。
	 *
	 * @param array<string, mixed> $row        整形済み商品行。
	 * @param bool                 $table_cell テーブルセル内表示（フッター余白なし）。
	 * @return string
	 */
	private function render_inquiry_button_html( array $row, bool $table_cell = false ): string {
		$acceptance_open = ! empty( $row['acceptance_open'] ) && empty( $row['is_pending'] ) && empty( $row['is_sold_out'] );
		$purchase_mode   = ! empty( $row['instant_purchase'] );
		$wrapper_class   = $table_cell
			? 'kantanbond-public-product-item__inquire-wrap kantanbond-public-product-item__inquire-wrap--table'
			: 'kantanbond-public-product-item__footer';

		if ( $acceptance_open ) {
			$label = $purchase_mode ? __( '購入する', 'kantanbond' ) : __( '問い合わす', 'kantanbond' );
			$btn_class = 'kantanbond-public-product-item__inquire-btn';
			if ( $purchase_mode ) {
				$btn_class .= ' kantanbond-public-product-item__purchase-btn';
			}

			return '<div class="' . esc_attr( $wrapper_class ) . '">'
				. '<button type="button" class="' . esc_attr( $btn_class ) . '">'
				. esc_html( $label )
				. '</button></div>';
		}

		$label = (string) ( $row['status_label'] ?? '' );
		$label = $this->resolve_availability_label(
			$label,
			(string) ( $row['availability_state'] ?? '' ),
			! empty( $row['is_sold_out'] ),
			! empty( $row['is_pending'] )
		);
		if ( $label === '' ) {
			$label = __( '受付停止', 'kantanbond' );
		}

		return '<div class="' . esc_attr( $wrapper_class ) . '">'
			. '<button type="button" class="kantanbond-public-product-item__inquire-btn" disabled>'
			. esc_html( $label )
			. '</button></div>';
	}

	/**
	 * @param array<string, mixed> $payload 商品データ。
	 * @param string               $extra_class 追加クラス。
	 * @return string
	 */
	private function item_attrs( array $payload, string $extra_class = '' ): string {
		$classes = trim( 'kantanbond-public-product-item ' . $extra_class );
		if ( ! empty( $payload['is_sold_out'] ) ) {
			$classes .= ' kantanbond-public-product-item--sold-out';
		} elseif ( ! empty( $payload['is_pending'] ) ) {
			$classes .= ' kantanbond-public-product-item--pending';
		}
		if ( ! empty( $payload['instant_purchase'] ) ) {
			$classes .= ' kantanbond-public-product-item--instant-purchase';
		}
		$category = isset( $payload['category'] ) ? (string) $payload['category'] : '';

		return ' class="' . esc_attr( $classes ) . '"'
			. ' data-category="' . esc_attr( $category ) . '"'
			. ' data-product="' . esc_attr( wp_json_encode( $payload ) ) . '"';
	}

	/**
	 * @param string $value yes/no 等。
	 * @return bool
	 */
	private function is_flag_enabled( string $value ): bool {
		$value = strtolower( trim( $value ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * API の status_label（翻訳キーのまま返る場合あり）を表示用日本語へ変換。
	 *
	 * @param string $label              API の status_label。
	 * @param string $availability_state open / sold_out / pending。
	 * @param bool   $is_sold_out        完売フラグ。
	 * @param bool   $is_pending         保留フラグ。
	 * @return string
	 */
	private function resolve_availability_label( string $label, string $availability_state, bool $is_sold_out, bool $is_pending ): string {
		$label = trim( $label );

		$key_map = array(
			'services.availability.sold_out' => __( '完売御礼！', 'kantanbond' ),
			'services.availability.pending'  => __( '保留中', 'kantanbond' ),
		);

		if ( $label !== '' && isset( $key_map[ $label ] ) ) {
			return $key_map[ $label ];
		}

		// Laravel 翻訳キーがそのまま渡された場合（services.xxx.yyy 等）
		if ( $label !== '' && str_contains( $label, '.' ) && ! preg_match( '/[\x{3040}-\x{30ff}\x{4e00}-\x{9faf}]/u', $label ) ) {
			$label = '';
		}

		if ( $label !== '' ) {
			return $label;
		}

		if ( $is_sold_out || $availability_state === 'sold_out' ) {
			return __( '完売御礼！', 'kantanbond' );
		}

		if ( $is_pending || $availability_state === 'pending' ) {
			return __( '保留中', 'kantanbond' );
		}

		return '';
	}

	/**
	 * category 属性（カンマ区切り可）を配列に変換する。
	 *
	 * @param string $category_attr カテゴリー属性。
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

	/**
	 * @param string $ids_attr カンマ区切り ID。
	 * @return array<int, int>
	 */
	private function parse_ids( string $ids_attr ): array {
		if ( $ids_attr === '' ) {
			return array();
		}

		$parts = preg_split( '/\s*,\s*/', $ids_attr );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $parts ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $products 商品一覧。
	 * @param string                           $order_by ソートキー。
	 * @param string                           $order    ASC|DESC。
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_products( array $products, string $order_by, string $order ): array {
		$allowed = array( 'id', 'name', 'price', 'frequency', 'category', 'tax_rate' );
		if ( ! in_array( $order_by, $allowed, true ) ) {
			$order_by = 'frequency';
		}

		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		usort(
			$products,
			static function ( array $a, array $b ) use ( $order_by, $order ): int {
				$va = $a[ $order_by ] ?? '';
				$vb = $b[ $order_by ] ?? '';

				if ( is_numeric( $va ) && is_numeric( $vb ) ) {
					$cmp = (float) $va <=> (float) $vb;
				} else {
					$cmp = strcmp( (string) $va, (string) $vb );
				}

				return $order === 'DESC' ? -$cmp : $cmp;
			}
		);

		return $products;
	}

	/**
	 * @param float $price 単価。
	 * @return string
	 */
	private function format_yen( float $price ): string {
		if ( abs( $price - round( $price ) ) < 0.00001 ) {
			$formatted = number_format( (int) round( $price ), 0, '.', ',' );
		} else {
			$formatted = number_format( $price, 2, '.', ',' );
		}

		return $formatted . '円';
	}
}
