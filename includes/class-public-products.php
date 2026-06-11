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

	/**
	 * @var KantanBond_API
	 */
	private KantanBond_API $api;

	/**
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * @param KantanBond_API $api API クライアント。
	 */
	public function __construct( KantanBond_API $api ) {
		$this->api = $api;
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
				'show_filter'   => 'yes',
			),
			$atts,
			'kantanbond_public_products'
		);

		$result = $this->api->get_public_products( sanitize_text_field( $atts['category'] ) );

		if ( is_wp_error( $result ) ) {
			return '<p class="kantanbond-public-products kantanbond-public-products--empty" role="alert">'
				. esc_html( $result->get_error_message() )
				. '</p>';
		}

		$products   = $result['products'];
		$categories = $result['categories'];
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
		);

		if ( $layout === 'table' ) {
			$inner = $this->render_table( $products, $display );
		} elseif ( $layout === 'cards' ) {
			$inner = $this->render_cards( $products, $display, $columns );
		} else {
			$inner = $this->render_grid( $products, $display, $columns );
		}

		$show_filter = $this->is_flag_enabled( $atts['show_filter'] );
		$filter_html = ( $show_filter && $categories !== array() )
			? $this->render_category_filter( $categories, sanitize_text_field( $atts['category'] ) )
			: '';

		$this->enqueue_assets();

		return '<div class="kantanbond-public-products kantanbond-public-products--' . esc_attr( $layout ) . '">'
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
				'i18n'    => array(
					'orderTitle'        => __( 'お問い合わせ', 'kantanbond' ),
					'submit'            => __( '送信する', 'kantanbond' ),
					'submitting'        => __( '送信中…', 'kantanbond' ),
					'close'             => __( '閉じる', 'kantanbond' ),
					'category'          => __( 'カテゴリ', 'kantanbond' ),
					'price'             => __( '単価', 'kantanbond' ),
					'unit'              => __( '単位', 'kantanbond' ),
					'tax'               => __( '税率', 'kantanbond' ),
					'memo'              => __( 'メモ', 'kantanbond' ),
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
			if ( $display['image'] && $row['image'] !== '' ) {
				$image_html = $this->render_product_image_html(
					$row['image'],
					$row['name'],
					'kantanbond-public-products-grid__image',
					'kantanbond-public-products-grid__image-wrap'
				);
			}

			$category_html = ( $display['category'] && $row['category'] !== '' )
				? '<p class="kantanbond-public-products-grid__category">' . esc_html( $row['category'] ) . '</p>'
				: '';

			$price_row_html = '';
			if ( $display['price'] || ( $display['unit'] && $row['unit'] !== '' ) ) {
				$price_part = $display['price']
					? '<span class="kantanbond-public-products-grid__price">' . esc_html( $row['price_display'] ) . '</span>'
					: '';
				$unit_part = ( $display['unit'] && $row['unit'] !== '' )
					? '<span class="kantanbond-public-products-grid__unit">/' . esc_html( $row['unit'] ) . '</span>'
					: '';
				$price_row_html = '<div class="kantanbond-public-products-grid__price-row">' . $price_part . $unit_part . '</div>';
			}

			$tax_html = ( $display['tax'] && $row['tax_rate'] !== '' )
				? '<span class="kantanbond-public-products-grid__tax">' . esc_html__( '税率', 'kantanbond' ) . ': ' . esc_html( $row['tax_rate'] ) . '%</span>'
				: '';

			$price_block = '';
			if ( $price_row_html !== '' || $tax_html !== '' ) {
				$price_block = '<div class="kantanbond-public-products-grid__price-block">' . $price_row_html . $tax_html . '</div>';
			}

			$memo_html = $display['memo']
				? $this->render_product_list_memo_html( $row['memo'], 'kantanbond-public-products-grid__memo' )
				: '';

			$items .= '<article' . $this->item_attrs( $payload, 'kantanbond-public-products-grid__item' ) . '>'
				. $image_html
				. '<div class="kantanbond-public-products-grid__body">'
				. '<h3 class="kantanbond-public-products-grid__name">' . esc_html( $row['name'] ) . '</h3>'
				. $category_html
				. $price_block
				. $memo_html
				. '</div></article>';
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
			if ( $display['image'] && $row['image'] !== '' ) {
				$image_html = $this->render_product_image_html(
					$row['image'],
					$row['name'],
					'kantanbond-public-products-card__image',
					'kantanbond-public-products-card__image-wrap'
				);
			}

			$category_html = ( $display['category'] && $row['category'] !== '' )
				? '<p class="kantanbond-public-products-card__category">' . esc_html( $row['category'] ) . '</p>'
				: '';

			$price_row_html = '';
			if ( $display['price'] || ( $display['unit'] && $row['unit'] !== '' ) ) {
				$price_part = $display['price']
					? '<span class="kantanbond-public-products-card__price">' . esc_html( $row['price_display'] ) . '</span>'
					: '';
				$unit_part = ( $display['unit'] && $row['unit'] !== '' )
					? '<span class="kantanbond-public-products-card__unit">/' . esc_html( $row['unit'] ) . '</span>'
					: '';
				$price_row_html = '<div class="kantanbond-public-products-card__price-row">' . $price_part . $unit_part . '</div>';
			}

			$tax_html = ( $display['tax'] && $row['tax_rate'] !== '' )
				? '<span class="kantanbond-public-products-card__tax">' . esc_html__( '税率', 'kantanbond' ) . ': ' . esc_html( $row['tax_rate'] ) . '%</span>'
				: '';

			$price_block = '';
			if ( $price_row_html !== '' || $tax_html !== '' ) {
				$price_block = '<div class="kantanbond-public-products-card__price-block">' . $price_row_html . $tax_html . '</div>';
			}

			$memo_html = $display['memo']
				? $this->render_product_list_memo_html( $row['memo'], 'kantanbond-public-products-card__memo' )
				: '';

			$items .= '<article' . $this->item_attrs( $payload, 'kantanbond-public-products-card' ) . '>'
				. $image_html
				. '<div class="kantanbond-public-products-card__body">'
				. $category_html
				. '<h3 class="kantanbond-public-products-card__name">' . esc_html( $row['name'] ) . '</h3>'
				. $price_block
				. $memo_html
				. '</div></article>';
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

		foreach ( $products as $product ) {
			$row     = $this->format_product_row( $product );
			$payload = $row;
			$cells   = array();

			if ( $display['image'] ) {
				$img = $row['image'] !== ''
					? $this->render_product_image_html(
						$row['image'],
						$row['name'],
						'kantanbond-public-products-thumb',
						'',
						'width="48" height="48"'
					)
					: '';
				$cells[] = '<td>' . $img . '</td>';
			}
			$cells[] = '<td>' . esc_html( $row['name'] ) . '</td>';
			if ( $display['category'] ) {
				$cells[] = '<td>' . esc_html( $row['category'] ) . '</td>';
			}
			if ( $display['price'] ) {
				$cells[] = '<td>' . esc_html( $row['price_display'] ) . '</td>';
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
			<div class="kantanbond-public-product-detail__panel" role="dialog" aria-modal="true" aria-labelledby="kantanbond-public-product-detail-title">
				<button type="button" class="kantanbond-public-product-detail__close" aria-label="<?php echo esc_attr__( '閉じる', 'kantanbond' ); ?>">&times;</button>
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
					<p class="kantanbond-public-product-order-form__field">
						<label for="kantanbond-pp-quantity"><?php echo esc_html__( '数量', 'kantanbond' ); ?></label>
						<input type="number" id="kantanbond-pp-quantity" name="quantity" min="1" step="1" value="1" />
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
					</p>
					<div class="kantanbond-public-product-order-form__message" role="status" aria-live="polite" hidden></div>
				</form>
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

		$image = '';
		if ( isset( $product['image_url'] ) && is_string( $product['image_url'] ) && $product['image_url'] !== '' ) {
			$image = $this->api->resolve_asset_url( $product['image_url'] );
		}

		return array(
			'id'            => isset( $product['id'] ) ? (int) $product['id'] : 0,
			'name'          => $name,
			'price'         => $price,
			'price_display' => $this->format_yen( $price ),
			'unit'          => $unit,
			'category'      => $category,
			'tax_rate'      => $tax_rate,
			'memo'          => $memo,
			'image'         => $image,
		);
	}

	/**
	 * 一覧ブロック内の商品画像 HTML（クリックで拡大表示）。
	 *
	 * @param string $image_url   画像 URL。
	 * @param string $name        商品名（alt・aria-label 用）。
	 * @param string $image_class 画像要素の CSS クラス。
	 * @param string $wrap_class  ラップ要素の CSS クラス（空なら省略）。
	 * @param string $extra_attrs img 要素の追加属性（例: width/height）。
	 * @return string
	 */
	private function render_product_image_html( string $image_url, string $name, string $image_class, string $wrap_class = '', string $extra_attrs = '' ): string {
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

		return '<div class="' . esc_attr( $wrap_class ) . '">' . $image_markup . '</div>';
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
			<figure class="kantanbond-public-product-image-lightbox__figure">
				<img class="kantanbond-public-product-image-lightbox__image" alt="" decoding="async" />
				<figcaption class="kantanbond-public-product-image-lightbox__caption"></figcaption>
			</figure>
			<button type="button" class="kantanbond-public-product-image-lightbox__close" aria-label="<?php echo esc_attr__( '閉じる', 'kantanbond' ); ?>">&times;</button>
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
	 * @param array<string, mixed> $payload 商品データ。
	 * @param string               $extra_class 追加クラス。
	 * @return string
	 */
	private function item_attrs( array $payload, string $extra_class = '' ): string {
		$classes  = trim( 'kantanbond-public-product-item ' . $extra_class );
		$category = isset( $payload['category'] ) ? (string) $payload['category'] : '';

		return ' class="' . esc_attr( $classes ) . '"'
			. ' role="button" tabindex="0"'
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
