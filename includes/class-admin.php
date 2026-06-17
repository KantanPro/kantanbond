<?php
/**
 * 管理画面メニューと各ページの描画。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KantanBond 管理画面を担当する。
 */
class KantanBond_Admin {

	public const MENU_SLUG           = 'kantanbond';
	public const PAGE_DASHBOARD      = 'kantanbond';
	public const PAGE_SETTINGS       = 'kantanbond-settings';
	public const PAGE_LOGS           = 'kantanbond-logs';
	public const NONCE_SETTINGS      = 'kantanbond_settings_nonce';
	public const ACTION_SAVE_SETTINGS = 'kantanbond_save_settings';

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
	 * API クライアント。
	 *
	 * @var KantanBond_API
	 */
	private KantanBond_API $api;

	/**
	 * @param KantanBond_Settings $settings 設定。
	 * @param KantanBond_Logger   $logger   ロガー。
	 * @param KantanBond_API      $api      API クライアント。
	 */
	public function __construct( KantanBond_Settings $settings, KantanBond_Logger $logger, KantanBond_API $api ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->api      = $api;
	}

	/**
	 * 管理画面フックを登録する。
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * 管理メニューを登録する。
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'KantanBond', 'kantanbond' ),
			__( 'KantanBond', 'kantanbond' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-admin-links',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'ダッシュボード', 'kantanbond' ),
			__( 'ダッシュボード', 'kantanbond' ),
			'manage_options',
			self::PAGE_DASHBOARD,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'API設定', 'kantanbond' ),
			__( 'API設定', 'kantanbond' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( '同期ログ', 'kantanbond' ),
			__( '同期ログ', 'kantanbond' ),
			'manage_options',
			self::PAGE_LOGS,
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * 管理画面用アセットを読み込む。
	 *
	 * @param string $hook_suffix 現在の admin ページフック。
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( strpos( $hook_suffix, 'kantanbond' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'kantanbond-admin',
			KANTANBOND_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			KANTANBOND_VERSION
		);
	}

	/**
	 * API 設定フォームの保存処理。
	 *
	 * @return void
	 */
	public function handle_settings_save(): void {
		if ( ! isset( $_POST['kantanbond_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'kantanbond' ) );
		}

		check_admin_referer( self::ACTION_SAVE_SETTINGS, self::NONCE_SETTINGS );

		$input = array(
			'api_base_url'  => isset( $_POST['kantanbond_api_base_url'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_api_base_url'] ) )
				: '',
			'api_token'     => isset( $_POST['kantanbond_api_token'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_api_token'] ) )
				: '',
			'api_secret'    => isset( $_POST['kantanbond_api_secret'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_api_secret'] ) )
				: '',
			'inbound_token' => isset( $_POST['kantanbond_inbound_token'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_inbound_token'] ) )
				: '',
			'public_product_card_bg_color' => isset( $_POST['kantanbond_public_product_card_bg_color'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_public_product_card_bg_color'] ) )
				: '',
		);

		$this->settings->save( $input );

		$this->logger->log(
			KantanBond_Logger::TYPE_INFO,
			__( 'API 設定を保存しました。', 'kantanbond' )
		);

		add_settings_error(
			'kantanbond_messages',
			'kantanbond_settings_saved',
			__( 'API 設定を保存しました。', 'kantanbond' ),
			'success'
		);
	}

	/**
	 * ダッシュボードページを描画する。
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'kantanbond' ) );
		}

		$is_configured = $this->settings->is_configured();
		$status_class  = $is_configured ? 'kantanbond-status-ready' : 'kantanbond-status-pending';
		$status_text   = $is_configured
			? __( '接続準備完了', 'kantanbond' )
			: __( 'API設定を行ってください', 'kantanbond' );

		$settings_url = admin_url( 'admin.php?page=' . self::PAGE_SETTINGS );
		$logs_url     = admin_url( 'admin.php?page=' . self::PAGE_LOGS );
		$log_count    = $this->logger->get_total_count();

		?>
		<div class="wrap kantanbond-wrap">
			<h1><?php echo esc_html__( 'KantanBond ダッシュボード', 'kantanbond' ); ?></h1>

			<div class="kantanbond-card">
				<h2><?php echo esc_html__( '接続ステータス', 'kantanbond' ); ?></h2>
				<p class="kantanbond-status <?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $status_text ); ?>
				</p>
				<?php if ( ! $is_configured ) : ?>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>">
							<?php echo esc_html__( 'API設定へ', 'kantanbond' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<div class="kantanbond-card">
				<h2><?php echo esc_html__( '概要', 'kantanbond' ); ?></h2>
				<ul class="kantanbond-overview-list">
					<li>
						<strong><?php echo esc_html__( 'API Base URL', 'kantanbond' ); ?>:</strong>
						<?php
						$base_url = $this->settings->get_base_url();
						if ( $base_url !== '' ) {
							echo '<code>' . esc_html( $base_url ) . '</code>';
						} else {
							echo esc_html__( '未設定', 'kantanbond' );
						}
						?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'API Secret', 'kantanbond' ); ?>:</strong>
						<?php
						$api_secret = $this->settings->get_api_secret();
						if ( $api_secret !== '' ) {
							echo '<code>' . esc_html( $api_secret ) . '</code>';
						} else {
							echo esc_html__( '未設定', 'kantanbond' );
						}
						?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'API アクセストークン', 'kantanbond' ); ?>:</strong>
						<?php
						echo $this->settings->get_api_token() !== ''
							? esc_html__( '設定済み', 'kantanbond' )
							: esc_html__( '未設定', 'kantanbond' );
						?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'インバウンドトークン', 'kantanbond' ); ?>:</strong>
						<?php
						echo $this->settings->get_inbound_token() !== ''
							? esc_html__( '設定済み', 'kantanbond' )
							: esc_html__( '未設定', 'kantanbond' );
						?>
					</li>
					<li>
						<strong><?php echo esc_html__( '同期ログ件数', 'kantanbond' ); ?>:</strong>
						<?php echo esc_html( (string) $log_count ); ?>
						<a href="<?php echo esc_url( $logs_url ); ?>"><?php echo esc_html__( 'ログを見る', 'kantanbond' ); ?></a>
					</li>
				</ul>
			</div>

			<?php $this->render_shortcodes_reference_section(); ?>

			<?php $this->render_public_access_notice(); ?>
		</div>
		<?php
	}

	/**
	 * API 設定ページを描画する。
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'kantanbond' ) );
		}

		settings_errors( 'kantanbond_messages' );

		$base_url         = $this->settings->get_base_url();
		$api_token        = $this->settings->get_api_token();
		$api_secret       = $this->settings->get_api_secret();
		$inbound_token    = $this->settings->get_inbound_token();
		$card_bg_color    = $this->settings->get_public_product_card_bg_color();
		$profile_url      = KantanBond_Settings::KANTANBIZ_PROFILE_URL;
		$inbound_help_url = KantanBond_Settings::KANTANBIZ_CONTACT_FORM_INBOUND_URL;

		?>
		<div class="wrap kantanbond-wrap">
			<h1><?php echo esc_html__( 'API設定', 'kantanbond' ); ?></h1>

			<div class="kantanbond-card kantanbond-help-card">
				<h2><?php echo esc_html__( '設定値の取得方法（KantanBizの場合）', 'kantanbond' ); ?></h2>
				<p class="description">
					<?php
					echo esc_html__(
						'API Base URL には KantanBiz アプリ本体（https://kantanbiz.cloud）を指定してください。https://www.kantanbiz.cloud は WordPress サイトであり、API 連携には使用できません。',
						'kantanbond'
					);
					?>
				</p>

				<h3><?php echo esc_html__( 'API アクセストークン', 'kantanbond' ); ?></h3>
				<ol class="kantanbond-help-list">
					<li>
						<a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html__( 'KantanBiz のプロフィール画面', 'kantanbond' ); ?>
						</a>
						<?php echo esc_html__( 'を開く', 'kantanbond' ); ?>
					</li>
					<li><?php echo esc_html__( '「API アクセストークン」セクションで「トークンを発行」をクリック', 'kantanbond' ); ?></li>
					<li><?php echo esc_html__( '一度だけ表示されるトークン文字列をコピーして「API アクセストークン」欄に貼り付け', 'kantanbond' ); ?></li>
				</ol>
				<p class="description">
					<?php echo esc_html__( '※ パスコードはトークン取得時（POST /api/v1/auth/token）に使うもので、この設定欄には入力しません。', 'kantanbond' ); ?>
				</p>

				<h3><?php echo esc_html__( 'API Secret', 'kantanbond' ); ?></h3>
				<p class="description">
					<?php echo esc_html__( 'KantanBiz 連携時は、接続先オフィスの ID を API Secret として設定します（X-Tenant-Id ヘッダに送信）。', 'kantanbond' ); ?>
				</p>
				<ol class="kantanbond-help-list">
					<li>
						<a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html__( 'KantanBiz のプロフィール画面', 'kantanbond' ); ?>
						</a>
						<?php echo esc_html__( 'を開く', 'kantanbond' ); ?>
					</li>
					<li><?php echo esc_html__( '「所有オフィス」または「所属オフィス」の一覧を確認', 'kantanbond' ); ?></li>
					<li>
						<?php
						echo esc_html__(
							'各オフィスの表示「ID: 3 / オフィス名-slug」の数値部分（例: 3）を「API Secret」欄に入力',
							'kantanbond'
						);
						?>
					</li>
				</ol>

				<h3><?php echo esc_html__( 'インバウンドトークン（公開商品・お申込み用）', 'kantanbond' ); ?></h3>
				<p class="description">
					<?php echo esc_html__( '[kantanbond_public_products] では、管理用 API トークンではなくインバウンドトークンを使用します（公開ページに PAT を出さないため）。', 'kantanbond' ); ?>
				</p>
				<ol class="kantanbond-help-list">
					<li>
						<a href="<?php echo esc_url( $inbound_help_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html__( 'KantanBiz の問い合わせ受信設定', 'kantanbond' ); ?>
						</a>
						<?php echo esc_html__( 'を開く', 'kantanbond' ); ?>
					</li>
					<li><?php echo esc_html__( '「トークンを新規発行」でトークンを発行し、下の欄に貼り付け', 'kantanbond' ); ?></li>
					<li><?php echo esc_html__( 'お申込み送信は WordPress サーバー経由（admin-ajax）で SaaS API にプロキシされます', 'kantanbond' ); ?></li>
				</ol>
			</div>

			<form method="post" action="" class="kantanbond-settings-form">
				<?php wp_nonce_field( self::ACTION_SAVE_SETTINGS, self::NONCE_SETTINGS ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="kantanbond_api_base_url"><?php echo esc_html__( 'API Base URL', 'kantanbond' ); ?></label>
							</th>
							<td>
								<input
									type="url"
									id="kantanbond_api_base_url"
									name="kantanbond_api_base_url"
									value="<?php echo esc_attr( $base_url ); ?>"
									class="regular-text"
									placeholder="https://kantanbiz.cloud"
									required
								/>
								<p class="description">
									<?php echo esc_html__( 'ローカル: KantanBiz（php artisan serve）の URL。WordPress が Docker 内のときは http://host.docker.internal:8000 を指定してください（localhost:8081 は WordPress 自身のため API 接続に使えません）。', 'kantanbond' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="kantanbond_api_token"><?php echo esc_html__( 'API アクセストークン', 'kantanbond' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="kantanbond_api_token"
									name="kantanbond_api_token"
									value="<?php echo esc_attr( $api_token ); ?>"
									class="large-text code"
									autocomplete="off"
									required
								/>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="kantanbond_api_secret"><?php echo esc_html__( 'API Secret', 'kantanbond' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="kantanbond_api_secret"
									name="kantanbond_api_secret"
									value="<?php echo esc_attr( $api_secret ); ?>"
									class="regular-text"
									autocomplete="off"
									required
								/>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="kantanbond_inbound_token"><?php echo esc_html__( 'インバウンドトークン', 'kantanbond' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="kantanbond_inbound_token"
									name="kantanbond_inbound_token"
									value="<?php echo esc_attr( $inbound_token ); ?>"
									class="large-text code"
									autocomplete="off"
								/>
								<p class="description">
									<?php echo esc_html__( '[kantanbond_public_products] 用。問い合わせ受信（CF7）と同じトークンを使用できます。', 'kantanbond' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="kantanbond_public_product_card_bg_color"><?php echo esc_html__( '公開商品カードの背景色', 'kantanbond' ); ?></label>
							</th>
							<td>
								<input
									type="color"
									id="kantanbond_public_product_card_bg_color"
									name="kantanbond_public_product_card_bg_color"
									value="<?php echo esc_attr( $card_bg_color ); ?>"
								/>
								<p class="description">
									<?php echo esc_html__( '[kantanbond_public_products] のグリッド型・カード型一覧で、各商品カードと画像エリアの背景色を設定します。', 'kantanbond' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( '設定を保存', 'kantanbond' ), 'primary', 'kantanbond_settings_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * ショートコード一覧・属性・記述例（KantanProEX 設定画面のショートコード欄を参考）。
	 *
	 * @return void
	 */
	private function render_shortcodes_reference_section(): void {
		?>
		<div class="kantanbond-card kantanbond-shortcodes-reference">
			<h2><?php echo esc_html__( 'ショートコード', 'kantanbond' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( '固定ページや投稿の本文に以下のショートコードを設置して利用できます。', 'kantanbond' ); ?>
			</p>

			<table class="widefat striped kantanbond-shortcodes-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'ショートコード', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '用途', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '備考', 'kantanbond' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>[kantanbond_customers]</code></td>
						<td><?php echo esc_html__( '顧客一覧', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'API 設定（PAT・オフィス ID）が必要。公開ページでは誰でも閲覧可能になります。', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>[kantanbond_projects]</code></td>
						<td><?php echo esc_html__( '案件一覧', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'API 設定が必要。', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>[kantanbond_products]</code></td>
						<td><?php echo esc_html__( '商品（サービス）一覧', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'API 設定が必要。', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>[kantanbond_services]</code></td>
						<td><?php echo esc_html__( '上記と同じ（別名）', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'API 設定が必要。', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>[kantanbond_reports]</code></td>
						<td><?php echo esc_html__( 'レポート・グラフ', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'API 設定が必要。属性 type / period 等で種別・期間を指定。', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>[kantanbond_public_products]</code></td>
						<td><?php echo esc_html__( '公開商品の一覧表示・Web お申込み', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'ログイン不要。KantanBiz で「サイトに公開」ON の商品のみ。インバウンドトークンが必要（PAT は不要）。', 'kantanbond' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3 class="kantanbond-shortcodes-subheading"><?php echo esc_html__( '[kantanbond_public_products] の属性', 'kantanbond' ); ?></h3>
			<table class="widefat striped kantanbond-shortcodes-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( '属性', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '既定値', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '説明', 'kantanbond' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>layout</code></td>
						<td><code>grid</code></td>
						<td><?php echo esc_html__( '表示形式: grid / table / cards', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>columns</code></td>
						<td><code>3</code></td>
						<td><?php echo esc_html__( '列数（1〜4）。grid / cards で有効', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>category</code></td>
						<td><?php echo esc_html__( '（空）', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( 'カテゴリで絞り込み（サーバー側）。複数指定時はカンマ区切り（例: サポート,WEB制作）。絞り込み UI 表示時は単一指定のみ初期値に使用', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>show_filter</code></td>
						<td><code>yes</code></td>
						<td><?php echo esc_html__( 'カテゴリ絞り込み UI（サジェスト付き）の表示 ON/OFF', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>ids</code></td>
						<td><?php echo esc_html__( '（空）', 'kantanbond' ); ?></td>
						<td><?php echo esc_html__( '表示する商品 ID（例: 2,5,8）', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>limit</code></td>
						<td><code>0</code></td>
						<td><?php echo esc_html__( '表示件数上限（0 で全件）', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>order_by</code></td>
						<td><code>frequency</code></td>
						<td><?php echo esc_html__( '並び順: id / name / price / frequency / category / tax_rate', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>order</code></td>
						<td><code>ASC</code></td>
						<td><?php echo esc_html__( '昇順 ASC / 降順 DESC', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>show_image</code> / <code>show_price</code> / <code>show_unit</code> / <code>show_category</code> / <code>show_tax</code> / <code>show_memo</code></td>
						<td><code>yes</code>（<code>show_tax</code> は <code>no</code>）</td>
						<td><?php echo esc_html__( '各項目の表示 ON/OFF（yes / no）', 'kantanbond' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3 class="kantanbond-shortcodes-subheading"><?php echo esc_html__( '[kantanbond_public_products] 記述例', 'kantanbond' ); ?></h3>
			<ul class="kantanbond-shortcodes-examples">
				<li><code>[kantanbond_public_products]</code></li>
				<li><code>[kantanbond_public_products layout="grid" columns="3"]</code></li>
				<li><code>[kantanbond_public_products layout="table"]</code></li>
				<li><code>[kantanbond_public_products layout="cards" columns="2" show_tax="yes"]</code></li>
				<li><code>[kantanbond_public_products category="Web制作" ids="2,5,8"]</code></li>
				<li><code>[kantanbond_public_products category="サポート,WEB制作"]</code></li>
				<li><code>[kantanbond_public_products limit="6" order_by="frequency" order="DESC"]</code></li>
				<li><code>[kantanbond_public_products show_filter="no" show_image="yes" show_category="no"]</code></li>
				<li><code>[kantanbond_public_products layout="cards" columns="4" category="一般" show_price="yes" show_unit="no"]</code></li>
			</ul>

			<h3 class="kantanbond-shortcodes-subheading"><?php echo esc_html__( '[kantanbond_reports] の属性', 'kantanbond' ); ?></h3>
			<table class="widefat striped kantanbond-shortcodes-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( '属性', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '既定値', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '説明', 'kantanbond' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>type</code></td>
						<td><code>sales</code></td>
						<td><?php echo esc_html__( 'sales / client / service / supplier / progress / staff_contribution / tax_return', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>period</code></td>
						<td><code>all_time</code></td>
						<td><?php echo esc_html__( 'all_time / this_year / last_year / this_month / last_month / last_3_months / last_6_months（tax_return 以外）', 'kantanbond' ); ?></td>
					</tr>
					<tr>
						<td><code>tax_year</code></td>
						<td><?php echo esc_html( gmdate( 'Y' ) ); ?></td>
						<td><?php echo esc_html__( 'type="tax_return" のときの対象年（4桁）', 'kantanbond' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3 class="kantanbond-shortcodes-subheading"><?php echo esc_html__( '[kantanbond_reports] 記述例', 'kantanbond' ); ?></h3>
			<ul class="kantanbond-shortcodes-examples">
				<li><code>[kantanbond_reports]</code></li>
				<li><code>[kantanbond_reports type="sales" period="this_month"]</code></li>
				<li><code>[kantanbond_reports type="client" period="this_year"]</code></li>
				<li><code>[kantanbond_reports type="service" period="last_3_months"]</code></li>
				<li><code>[kantanbond_reports type="tax_return" tax_year="<?php echo esc_attr( gmdate( 'Y' ) ); ?>"]</code></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * 公開ページ設置時の注意書きを表示する。
	 *
	 * @return void
	 */
	private function render_public_access_notice(): void {
		?>
		<div class="kantanbond-card kantanbond-notice-card">
			<h2><?php echo esc_html__( '公開ページへの設置について', 'kantanbond' ); ?></h2>
			<p class="kantanbond-notice kantanbond-notice-warning">
				<?php
				echo esc_html__(
					'ショートコードを公開ページ（固定ページ・投稿）に設置すると、KantanBiz から取得したデータがインターネット上の誰でも閲覧できる状態になります。',
					'kantanbond'
				);
				?>
			</p>
			<p>
				<?php
				echo esc_html__(
					'社内限定や関係者のみに見せたい場合は、WordPress の「パスワード保護」、閲覧権限の設定、会員限定プラグインなどでページへのアクセスを制限してください。',
					'kantanbond'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * 同期ログページを描画する。
	 *
	 * @return void
	 */
	public function render_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'kantanbond' ) );
		}

		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;

		$logs        = $this->logger->get_logs( $per_page, $offset );
		$total_count = $this->logger->get_total_count();
		$total_pages = (int) ceil( $total_count / $per_page );

		?>
		<div class="wrap kantanbond-wrap">
			<h1><?php echo esc_html__( '同期ログ', 'kantanbond' ); ?></h1>

			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: log count */
						__( '全 %d 件', 'kantanbond' ),
						$total_count
					)
				);
				?>
			</p>

			<table class="widefat striped kantanbond-logs-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'ID', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '日時', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( '種別', 'kantanbond' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'メッセージ', 'kantanbond' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="4"><?php echo esc_html__( 'ログがありません。', 'kantanbond' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $log->id ); ?></td>
								<td><?php echo esc_html( (string) $log->created_at ); ?></td>
								<td>
									<span class="kantanbond-log-type kantanbond-log-type-<?php echo esc_attr( (string) $log->type ); ?>">
										<?php echo esc_html( (string) $log->type ); ?>
									</span>
								</td>
								<td><?php echo esc_html( (string) $log->message ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
