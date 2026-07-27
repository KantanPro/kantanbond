<?php
/**
 * [kantanbond_billing_plans] KantanBiz 料金プラン（フリー・ソロ・チーム・ビジネス）表示。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KantanBiz 公式サイト向けの料金プラン選択 UI をショートコードで表示する。
 *
 * 一般配布では誤用防止のため、合言葉（unlock）または wp-config のオプトインが必要。
 * 例: [kantanbond_billing_plans unlock="（合言葉）"]
 * または `define( 'KANTANBOND_ENABLE_BILLING_PLANS', true );`
 *
 * プラン定義は KantanBiz（config/billing.php / lang/ja/billing.php）に合わせて静的に保持する。
 * 有料プランの CTA は /register?plan=&interval= 経由で Stripe Checkout へ誘導する。
 */
class KantanBond_Billing_Plans {

	/**
	 * ショートコード属性 unlock の既定合言葉。
	 */
	public const DEFAULT_UNLOCK_PHRASE = 'kantanbiz-plans';

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
	 * サイト全体で料金プランショートコードを無条件有効にするか。
	 *
	 * wp-config.php 等で `define( 'KANTANBOND_ENABLE_BILLING_PLANS', true );` を定義したとき。
	 * フィルター `kantanbond_enable_billing_plans` でも上書きできる。
	 * （合言葉 unlock なしでも表示可能）
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$enabled = defined( 'KANTANBOND_ENABLE_BILLING_PLANS' ) && KANTANBOND_ENABLE_BILLING_PLANS;

		/**
		 * 料金プランショートコードのサイト全体有効/無効。
		 *
		 * @param bool $enabled 既定は定数 KANTANBOND_ENABLE_BILLING_PLANS。
		 */
		return (bool) apply_filters( 'kantanbond_enable_billing_plans', $enabled );
	}

	/**
	 * unlock 属性で照合する合言葉。
	 *
	 * 定数 `KANTANBOND_BILLING_PLANS_UNLOCK` またはフィルターで変更可能。
	 *
	 * @return string
	 */
	public static function unlock_phrase(): string {
		$phrase = self::DEFAULT_UNLOCK_PHRASE;

		if ( defined( 'KANTANBOND_BILLING_PLANS_UNLOCK' ) && is_string( KANTANBOND_BILLING_PLANS_UNLOCK ) ) {
			$custom = trim( KANTANBOND_BILLING_PLANS_UNLOCK );
			if ( $custom !== '' ) {
				$phrase = $custom;
			}
		}

		/**
		 * 料金プランショートコードの unlock 合言葉。
		 *
		 * @param string $phrase 既定合言葉（または定数 KANTANBOND_BILLING_PLANS_UNLOCK）。
		 */
		$filtered = apply_filters( 'kantanbond_billing_plans_unlock_phrase', $phrase );

		return is_string( $filtered ) && trim( $filtered ) !== ''
			? trim( $filtered )
			: self::DEFAULT_UNLOCK_PHRASE;
	}

	/**
	 * 表示が許可されているか（サイト全体オプトイン、または合言葉一致）。
	 *
	 * @param string $unlock_attr ショートコード属性 unlock。
	 * @return bool
	 */
	public static function is_unlocked( string $unlock_attr = '' ): bool {
		if ( self::is_enabled() ) {
			return true;
		}

		$provided = trim( $unlock_attr );
		if ( $provided === '' ) {
			return false;
		}

		return hash_equals( self::unlock_phrase(), $provided );
	}

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public function init(): void {
		// 合言葉付きで個別有効化できるよう、ショートコード自体は常に登録する。
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
				'plans'                => 'free,starter,standard,business',
				'highlight'            => 'standard',
				'show_yearly'          => 'yes',
				'show_common_features' => 'no',
				'default_interval'     => 'month',
				'free_cta_label'       => '',
				'paid_cta_label'       => '',
				'cta_url'              => '',
				'select'               => 'yes',
				'unlock'               => '',
			),
			$atts,
			'kantanbond_billing_plans'
		);

		if ( ! self::is_unlocked( (string) $atts['unlock'] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="kantanbond-billing-plans kantanbond-billing-plans--locked" role="alert">'
					. esc_html__( '料金プランを表示するには unlock 属性に合言葉が必要です（合言葉はソース上の定数、または運営からの案内を参照）。wp-config で全体有効化している場合は unlock 不要です。', 'kantanbond' )
					. '</p>';
			}

			return '';
		}

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
		$default_interval     = $this->normalize_interval( (string) $atts['default_interval'] );

		$free_cta_label = trim( (string) $atts['free_cta_label'] );
		if ( $free_cta_label === '' ) {
			$free_cta_label = __( '無料で始める', 'kantanbond' );
		}
		$paid_cta_label = trim( (string) $atts['paid_cta_label'] );
		if ( $paid_cta_label === '' ) {
			$paid_cta_label = __( '申し込む', 'kantanbond' );
		}

		$register_url = $this->resolve_register_url( (string) $atts['cta_url'] );
		$columns      = min( 4, max( 1, count( $plans ) ) );

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
			data-register-url="<?php echo esc_url( $register_url ); ?>"
			data-default-interval="<?php echo esc_attr( $default_interval ); ?>"
			style="--kantanbond-plans-columns: <?php echo esc_attr( (string) $columns ); ?>"
		>
			<div class="kantanbond-billing-plans__grid" role="list">
				<?php foreach ( $plans as $plan_id => $plan ) : ?>
					<?php
					$is_free        = ! empty( $plan['is_free'] );
					$is_recommended = ! empty( $plan['recommended'] );
					$radio_id       = $uid . '-' . $plan_id;
					$features       = $this->features_for_plan( $plan, $show_common_features );
					$cta_label      = $is_free ? $free_cta_label : $paid_cta_label;
					$cta_href       = $is_free
						? add_query_arg( 'plan', 'free', $register_url )
						: add_query_arg(
							array(
								'plan'     => $plan_id,
								'interval' => $default_interval,
							),
							$register_url
						);
					?>
					<div
						class="kantanbond-billing-plans__item<?php echo $is_recommended ? ' kantanbond-billing-plans__item--recommended' : ''; ?><?php echo $is_free ? ' kantanbond-billing-plans__item--free' : ''; ?>"
						role="listitem"
						data-plan="<?php echo esc_attr( $plan_id ); ?>"
						data-paid="<?php echo $is_free ? '0' : '1'; ?>"
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
								<?php if ( ! $is_free && $show_yearly && ! empty( $plan['price_yearly_label'] ) ) : ?>
									<span class="kantanbond-billing-plans__yearly" data-yearly-price>
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
								<?php if ( ! $is_free ) : ?>
									<fieldset class="kantanbond-billing-plans__interval">
										<legend class="kantanbond-billing-plans__interval-legend"><?php echo esc_html__( '支払い間隔', 'kantanbond' ); ?></legend>
										<div class="kantanbond-billing-plans__interval-toggle" role="group" aria-label="<?php echo esc_attr__( '年払または月払', 'kantanbond' ); ?>">
											<label class="kantanbond-billing-plans__interval-option">
												<input
													type="radio"
													class="kantanbond-billing-plans__interval-input"
													name="<?php echo esc_attr( $uid . '-interval-' . $plan_id ); ?>"
													value="year"
													data-plan="<?php echo esc_attr( $plan_id ); ?>"
													<?php checked( $default_interval, 'year' ); ?>
												/>
												<span><?php echo esc_html__( '年払', 'kantanbond' ); ?></span>
											</label>
											<span class="kantanbond-billing-plans__interval-sep" aria-hidden="true">｜</span>
											<label class="kantanbond-billing-plans__interval-option">
												<input
													type="radio"
													class="kantanbond-billing-plans__interval-input"
													name="<?php echo esc_attr( $uid . '-interval-' . $plan_id ); ?>"
													value="month"
													data-plan="<?php echo esc_attr( $plan_id ); ?>"
													<?php checked( $default_interval, 'month' ); ?>
												/>
												<span><?php echo esc_html__( '月払', 'kantanbond' ); ?></span>
											</label>
										</div>
									</fieldset>
									<p class="kantanbond-billing-plans__stripe-note">
										<?php echo esc_html__( '申し込み後、Stripe の決済画面へ進みます。', 'kantanbond' ); ?>
									</p>
								<?php else : ?>
									<p class="kantanbond-billing-plans__stripe-note">
										<?php echo esc_html__( '開始時に Stripe でクレジットカード登録が必要です（この時点では課金されません）。', 'kantanbond' ); ?>
									</p>
								<?php endif; ?>

								<a
									class="kantanbond-billing-plans__cta"
									href="<?php echo esc_url( $cta_href ); ?>"
									data-plan="<?php echo esc_attr( $plan_id ); ?>"
									data-paid="<?php echo $is_free ? '0' : '1'; ?>"
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

		$css_path = KANTANBOND_PLUGIN_DIR . 'assets/css/billing-plans.css';
		$js_path  = KANTANBOND_PLUGIN_DIR . 'assets/js/billing-plans.js';
		$css_ver  = is_readable( $css_path ) ? (string) filemtime( $css_path ) : KANTANBOND_VERSION;
		$js_ver   = is_readable( $js_path ) ? (string) filemtime( $js_path ) : KANTANBOND_VERSION;

		wp_enqueue_style(
			'kantanbond-billing-plans',
			KANTANBOND_PLUGIN_URL . 'assets/css/billing-plans.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'kantanbond-billing-plans',
			KANTANBOND_PLUGIN_URL . 'assets/js/billing-plans.js',
			array(),
			$js_ver,
			true
		);

		self::$assets_enqueued = true;
	}

	/**
	 * 登録 URL を解決する（空なら KantanBiz の /register）。
	 *
	 * @param string $raw 属性値。
	 * @return string
	 */
	private function resolve_register_url( string $raw ): string {
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
	 * @param string $value 属性値。
	 * @return bool
	 */
	private function is_yes( string $value ): bool {
		$value = strtolower( trim( $value ) );

		return in_array( $value, array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * @param string $raw month / year。
	 * @return string
	 */
	private function normalize_interval( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		$aliases = array(
			'year'    => 'year',
			'yearly'  => 'year',
			'年'      => 'year',
			'年払'    => 'year',
			'年払い'  => 'year',
			'month'   => 'month',
			'monthly' => 'month',
			'月'      => 'month',
			'月払'    => 'month',
			'月払い'  => 'month',
		);

		return $aliases[ $key ] ?? 'month';
	}

	/**
	 * @param string $raw カンマ区切り。
	 * @return list<string>
	 */
	private function parse_plan_ids( string $raw ): array {
		$parts = preg_split( '/\s*,\s*/', trim( $raw ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$aliases = array(
			'free'     => 'free',
			'フリー'   => 'free',
			'trial'    => 'free',
			'trial30'  => 'free',
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
	 * @param array<string, mixed> $plan                 プラン定義。
	 * @param bool                 $show_common_features 共通機能も出すか。
	 * @return list<string>
	 */
	private function features_for_plan( array $plan, bool $show_common_features ): array {
		$lines   = array();
		$is_free = ! empty( $plan['is_free'] );

		foreach ( array( 'member_limit', 'client_limit', 'order_limit', 'service_limit', 'supplier_limit', 'order_files_storage', 'backup_upload' ) as $key ) {
			if ( ! empty( $plan[ $key ] ) && is_string( $plan[ $key ] ) ) {
				$lines[] = $plan[ $key ];
			}
		}

		if ( $show_common_features ) {
			$lines = array_merge( $lines, $is_free ? $this->get_free_features() : $this->get_common_features() );
		}

		return $lines;
	}

	/**
	 * @return list<string>
	 */
	private function get_free_features(): array {
		return array(
			__( '案件の進捗管理（見積・受注・請求など）', 'kantanbond' ),
			__( '案件進捗別メール送信', 'kantanbond' ),
			__( '顧客・自社商品・協力会社の基本管理', 'kantanbond' ),
			__( '帳票表示設定', 'kantanbond' ),
			__( '消費税対応', 'kantanbond' ),
			__( '各種レポート', 'kantanbond' ),
			__( '紹介プログラム', 'kantanbond' ),
			__( '見積・請求・発注メール末尾に広告（有料プランで解除）', 'kantanbond' ),
			__( 'データのバックアップ・インポートは利用不可（有料プランで利用可）', 'kantanbond' ),
		);
	}

	/**
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
	 * @return array<string, array<string, mixed>>
	 */
	private function get_plans(): array {
		return array(
			'free'     => array(
				'name'                => __( 'フリー', 'kantanbond' ),
				'price_label'         => '¥0',
				'period'              => __( '永続（機能制限あり）', 'kantanbond' ),
				'tagline'             => __( 'おひとり運用向けの無料プラン。基本の受発注は続けられ、本格利用時はソロ以上へ。', 'kantanbond' ),
				'is_free'             => true,
				'member_limit'        => __( 'スタッフ（ログインユーザー）1 名まで（スタッフ招待不可）', 'kantanbond' ),
				'service_limit'       => __( '自社商品 10 件まで', 'kantanbond' ),
				'supplier_limit'      => __( '協力会社 5 件まで', 'kantanbond' ),
				'order_files_storage' => __( '案件ファイル 100 MB まで', 'kantanbond' ),
			),
			'starter'  => array(
				'name'                => __( 'ソロ', 'kantanbond' ),
				'price_label'         => '¥980',
				'period'              => __( '月額（税別）', 'kantanbond' ),
				'price_yearly_label'  => '¥8,500',
				'period_yearly'       => __( '年（税別）', 'kantanbond' ),
				'tagline'             => __( 'ひとりで完結する最小プラン。フリーランス、一人親方、個人の副業など、おひとり運用に。', 'kantanbond' ),
				'member_limit'        => __( 'スタッフ（ログインユーザー）1 名まで（オーナー含む）', 'kantanbond' ),
				'service_limit'       => __( '自社商品 100 件まで', 'kantanbond' ),
				'order_files_storage' => __( '案件ファイル 1 GB まで（追加購入可能）', 'kantanbond' ),
				'backup_upload'       => __( 'バックアップ JSON 50 MB まで（追加購入可能）', 'kantanbond' ),
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
				'order_files_storage' => __( '案件ファイル 10 GB まで（追加購入可能）', 'kantanbond' ),
				'backup_upload'       => __( 'バックアップ JSON 200 MB まで（追加購入可能）', 'kantanbond' ),
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
				'order_files_storage' => __( '案件ファイル 50 GB まで（追加購入可能）', 'kantanbond' ),
				'backup_upload'       => __( 'バックアップ JSON 500 MB まで（追加購入可能）', 'kantanbond' ),
			),
		);
	}
}
