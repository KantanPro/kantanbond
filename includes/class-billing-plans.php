<?php
/**
 * [kantanbond_billing_plans] KantanBiz 料金プラン（ソロ・チーム・ビジネス）表示。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KantanBiz の有料プラン選択 UI をショートコードで表示する。
 *
 * プラン定義は KantanBiz（config/billing.php / lang/ja/billing.php）に合わせて静的に保持する。
 * 公開 API が無いため、API トークンは不要。
 */
class KantanBond_Billing_Plans {

	/**
	 * @var KantanBond_Settings
	 */
	private KantanBond_Settings $settings;

	/**
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * @param KantanBond_Settings $settings 設定。
	 */
	public function __construct( KantanBond_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'kantanbond_billing_plans', array( $this, 'render_shortcode' ) );
		add_shortcode( 'kantanbond_plans', array( $this, 'render_shortcode' ) );
	}

	/**
	 * プラン選択ショートコードを描画する。
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
				'align'                => 'center',
				'plans'                => 'starter,standard,business',
				'highlight'            => 'standard',
				'show_yearly'          => 'yes',
				'show_common_features' => 'no',
				'cta_label'            => '',
				'cta_url'              => '',
				'select'               => 'yes',
			),
			$atts,
			'kantanbond_billing_plans'
		);

		$plan_ids = $this->parse_plan_ids( (string) $atts['plans'] );
		if ( $plan_ids === array() ) {
			return '<p class="kantanbond-billing-plans kantanbond-billing-plans--empty" role="alert">'
				. esc_html__( '表示するプランがありません。', 'kantanbond' )
				. '</p>';
		}

		$all_plans = $this->get_plans();
		$plans     = array();
		foreach ( $plan_ids as $plan_id ) {
			if ( isset( $all_plans[ $plan_id ] ) ) {
				$plans[ $plan_id ] = $all_plans[ $plan_id ];
			}
		}

		if ( $plans === array() ) {
			return '<p class="kantanbond-billing-plans kantanbond-billing-plans--empty" role="alert">'
				. esc_html__( '表示するプランがありません。', 'kantanbond' )
				. '</p>';
		}

		$highlight = sanitize_key( (string) $atts['highlight'] );
		if ( ! isset( $plans[ $highlight ] ) ) {
			$highlight = array_key_exists( 'standard', $plans )
				? 'standard'
				: (string) array_key_first( $plans );
		}

		$show_yearly          = $this->is_yes( (string) $atts['show_yearly'] );
		$show_common_features = $this->is_yes( (string) $atts['show_common_features'] );
		$interactive          = $this->is_yes( (string) $atts['select'] );
		$cta_label            = trim( (string) $atts['cta_label'] );
		if ( $cta_label === '' ) {
			$cta_label = __( '無料で始める', 'kantanbond' );
		}
		$cta_url = $this->resolve_cta_url( (string) $atts['cta_url'] );

		$this->enqueue_assets();

		$classes = KantanBond_Shortcode_Align::merge_classes(
			'kantanbond-billing-plans',
			(string) $atts['align'],
			'kantanbond-billing-plans'
		);
		$uid     = wp_unique_id( 'kantanbond-plans-' );

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( $classes ); ?>"
			data-kantanbond-billing-plans
			data-interactive="<?php echo $interactive ? '1' : '0'; ?>"
		>
			<div class="kantanbond-billing-plans__grid" role="list">
				<?php foreach ( $plans as $plan_id => $plan ) : ?>
					<?php
					$is_recommended = ! empty( $plan['recommended'] );
					$radio_id       = $uid . '-' . $plan_id;
					$features       = $this->features_for_plan( $plan, $show_common_features );
					$plan_cta       = add_query_arg( 'plan', $plan_id, $cta_url );
					?>
					<div
						class="kantanbond-billing-plans__item<?php echo $is_recommended ? ' kantanbond-billing-plans__item--recommended' : ''; ?>"
						role="listitem"
					>
						<?php if ( $is_recommended ) : ?>
							<span class="kantanbond-billing-plans__badge"><?php echo esc_html__( 'おすすめ', 'kantanbond' ); ?></span>
						<?php endif; ?>

						<?php if ( $interactive ) : ?>
							<input
								type="radio"
								class="kantanbond-billing-plans__radio"
								name="<?php echo esc_attr( $uid ); ?>"
								id="<?php echo esc_attr( $radio_id ); ?>"
								value="<?php echo esc_attr( $plan_id ); ?>"
								<?php checked( $plan_id, $highlight ); ?>
							/>
						<?php endif; ?>

						<div class="kantanbond-billing-plans__card<?php echo $is_recommended ? ' kantanbond-billing-plans__card--recommended' : ''; ?>">
							<?php if ( $interactive ) : ?>
								<label class="kantanbond-billing-plans__label" for="<?php echo esc_attr( $radio_id ); ?>">
							<?php else : ?>
								<div class="kantanbond-billing-plans__label">
							<?php endif; ?>

								<span class="kantanbond-billing-plans__name"><?php echo esc_html( $plan['name'] ); ?></span>
								<span class="kantanbond-billing-plans__price">
									<?php echo esc_html( $plan['price_label'] ); ?>
									<span class="kantanbond-billing-plans__period"> / <?php echo esc_html( $plan['period'] ); ?></span>
								</span>
								<?php if ( $show_yearly && ! empty( $plan['price_yearly_label'] ) ) : ?>
									<span class="kantanbond-billing-plans__yearly">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: yearly price, 2: yearly period */
												__( '年払い %1$s / %2$s', 'kantanbond' ),
												$plan['price_yearly_label'],
												$plan['period_yearly']
											)
										);
										?>
									</span>
								<?php endif; ?>
								<span class="kantanbond-billing-plans__tagline"><?php echo esc_html( $plan['tagline'] ); ?></span>

								<?php if ( $features !== array() ) : ?>
									<ul class="kantanbond-billing-plans__features">
										<?php foreach ( $features as $feature ) : ?>
											<li>
												<span class="kantanbond-billing-plans__check" aria-hidden="true">✔</span>
												<span><?php echo esc_html( $feature ); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>

							<?php if ( $interactive ) : ?>
								</label>
							<?php else : ?>
								</div>
							<?php endif; ?>

							<div class="kantanbond-billing-plans__cta-wrap">
								<a
									class="kantanbond-billing-plans__cta"
									href="<?php echo esc_url( $plan_cta ); ?>"
									data-plan="<?php echo esc_attr( $plan_id ); ?>"
								><?php echo esc_html( $cta_label ); ?></a>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * アセットを読み込む。
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		wp_enqueue_style(
			'kantanbond-billing-plans',
			KANTANBOND_PLUGIN_URL . 'assets/css/billing-plans.css',
			array(),
			KANTANBOND_VERSION
		);

		wp_enqueue_script(
			'kantanbond-billing-plans',
			KANTANBOND_PLUGIN_URL . 'assets/js/billing-plans.js',
			array(),
			KANTANBOND_VERSION,
			true
		);

		self::$assets_enqueued = true;
	}

	/**
	 * CTA URL を解決する（空なら KantanBiz の /register）。
	 *
	 * @param string $raw 属性値。
	 * @return string
	 */
	private function resolve_cta_url( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw !== '' ) {
			return esc_url_raw( $raw );
		}

		$base = $this->settings->get_browser_base_url();
		if ( $base === '' ) {
			$base = KantanBond_Settings::KANTANBIZ_APP_URL;
		}

		return rtrim( $base, '/' ) . '/register';
	}

	/**
	 * yes / 1 / true を真とみなす。
	 *
	 * @param string $value 属性値。
	 * @return bool
	 */
	private function is_yes( string $value ): bool {
		$value = strtolower( trim( $value ) );

		return in_array( $value, array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * plans 属性をパースする。
	 *
	 * @param string $raw カンマ区切り。
	 * @return list<string>
	 */
	private function parse_plan_ids( string $raw ): array {
		$parts = preg_split( '/\s*,\s*/', trim( $raw ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$aliases = array(
			'solo'     => 'starter',
			'ソロ'     => 'starter',
			'team'     => 'standard',
			'チーム'   => 'standard',
			'ビジネス' => 'business',
		);

		$ids = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' ) {
				continue;
			}
			if ( isset( $aliases[ $part ] ) ) {
				$part = $aliases[ $part ];
			}
			$key = sanitize_key( $part );
			if ( $key !== '' && ! in_array( $key, $ids, true ) ) {
				$ids[] = $key;
			}
		}

		return $ids;
	}

	/**
	 * プラン別の表示機能一覧を返す。
	 *
	 * @param array<string, mixed> $plan                 プラン定義。
	 * @param bool                 $show_common_features 共通機能も出すか。
	 * @return list<string>
	 */
	private function features_for_plan( array $plan, bool $show_common_features ): array {
		$lines = array();
		if ( ! empty( $plan['member_limit'] ) && is_string( $plan['member_limit'] ) ) {
			$lines[] = $plan['member_limit'];
		}
		if ( ! empty( $plan['service_limit'] ) && is_string( $plan['service_limit'] ) ) {
			$lines[] = $plan['service_limit'];
		}
		if ( ! empty( $plan['order_files_storage'] ) && is_string( $plan['order_files_storage'] ) ) {
			$lines[] = $plan['order_files_storage'];
		}
		if ( ! empty( $plan['backup_upload'] ) && is_string( $plan['backup_upload'] ) ) {
			$lines[] = $plan['backup_upload'];
		}

		if ( $show_common_features ) {
			$lines = array_merge( $lines, $this->get_common_features() );
		}

		return $lines;
	}

	/**
	 * 有料プラン共通機能。
	 *
	 * @return list<string>
	 */
	private function get_common_features(): array {
		return array(
			__( '案件の進捗管理（見積・受注・請求など）', 'kantanbond' ),
			__( '案件進捗別メール送信', 'kantanbond' ),
			__( '案件メール送信履歴', 'kantanbond' ),
			__( '案件ファイル格納', 'kantanbond' ),
			__( '顧客管理', 'kantanbond' ),
			__( '自社商品管理', 'kantanbond' ),
			__( '協力会社管理', 'kantanbond' ),
			__( '各種レポート', 'kantanbond' ),
			__( '帳票表示設定', 'kantanbond' ),
			__( '消費税対応', 'kantanbond' ),
			__( '紹介プログラム', 'kantanbond' ),
			__( 'Webhook 対応', 'kantanbond' ),
			__( 'REST API 連携', 'kantanbond' ),
			__( 'Contact Form 7 からの問い合わせ受信', 'kantanbond' ),
			__( 'データのバックアップ・インポート（JSON）', 'kantanbond' ),
			__( '追加ストレージの購入（有料プラン・オプション）', 'kantanbond' ),
			__( 'FileMaker Pro 版からの移行（柔軟インポート）', 'kantanbond' ),
		);
	}

	/**
	 * KantanBiz 有料プラン定義（表示用）。
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_plans(): array {
		return array(
			'starter'  => array(
				'name'                => __( 'ソロ', 'kantanbond' ),
				'price_label'         => '¥980',
				'period'              => __( '月額（税別）', 'kantanbond' ),
				'price_yearly_label'  => '¥8,500',
				'period_yearly'       => __( '年（税別）', 'kantanbond' ),
				'tagline'             => __( 'ひとりで完結する最小プラン。フリーランス、一人親方、個人の副業など、おひとり運用に。', 'kantanbond' ),
				'member_limit'        => __( 'スタッフ（ログインユーザー）1 名まで（オーナー含む）', 'kantanbond' ),
				'service_limit'       => __( '自社商品 100 件まで', 'kantanbond' ),
				'order_files_storage' => __( '案件ファイル 1 GB まで', 'kantanbond' ),
				'backup_upload'       => __( 'バックアップ JSON 50 MB まで', 'kantanbond' ),
			),
			'standard' => array(
				'name'                => __( 'チーム', 'kantanbond' ),
				'price_label'         => '¥2,980',
				'period'              => __( '月額（税別）', 'kantanbond' ),
				'price_yearly_label'  => '¥25,900',
				'period_yearly'       => __( '年（税別）', 'kantanbond' ),
				'tagline'             => __( '小さなチームの定番。機能はソロ・ビジネスと同じで、スタッフ枠が広がり分担しやすくなります。', 'kantanbond' ),
				'recommended'         => true,
				'member_limit'        => __( 'スタッフ（ログインユーザー）5 名まで（オーナー含む）', 'kantanbond' ),
				'service_limit'       => __( '自社商品 500 件まで', 'kantanbond' ),
				'order_files_storage' => __( '案件ファイル 10 GB まで', 'kantanbond' ),
				'backup_upload'       => __( 'バックアップ JSON 200 MB まで', 'kantanbond' ),
			),
			'business' => array(
				'name'                => __( 'ビジネス', 'kantanbond' ),
				'price_label'         => '¥5,980',
				'period'              => __( '月額（税別）', 'kantanbond' ),
				'price_yearly_label'  => '¥52,000',
				'period_yearly'       => __( '年（税別）', 'kantanbond' ),
				'tagline'             => __( '大所帯・複数現場のまとめ役向け。スタッフ枠最大で、担当分けや部門運用にも向きます。', 'kantanbond' ),
				'member_limit'        => __( 'スタッフ（ログインユーザー）15 名まで（オーナー含む）', 'kantanbond' ),
				'service_limit'       => __( '自社商品 2,000 件まで', 'kantanbond' ),
				'order_files_storage' => __( '案件ファイル 50 GB まで', 'kantanbond' ),
				'backup_upload'       => __( 'バックアップ JSON 500 MB まで', 'kantanbond' ),
			),
		);
	}
}
