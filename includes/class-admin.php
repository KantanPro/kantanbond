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
			'api_base_url' => isset( $_POST['kantanbond_api_base_url'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_api_base_url'] ) )
				: '',
			'api_token'    => isset( $_POST['kantanbond_api_token'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_api_token'] ) )
				: '',
			'api_secret'   => isset( $_POST['kantanbond_api_secret'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['kantanbond_api_secret'] ) )
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
						<strong><?php echo esc_html__( '同期ログ件数', 'kantanbond' ); ?>:</strong>
						<?php echo esc_html( (string) $log_count ); ?>
						<a href="<?php echo esc_url( $logs_url ); ?>"><?php echo esc_html__( 'ログを見る', 'kantanbond' ); ?></a>
					</li>
					<li>
						<strong><?php echo esc_html__( 'ショートコード', 'kantanbond' ); ?>:</strong>
						<code>[kantanbond_customers]</code>,
						<code>[kantanbond_projects]</code>,
						<code>[kantanbond_products]</code>
					</li>
				</ul>
			</div>

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

		$base_url    = $this->settings->get_base_url();
		$api_token   = $this->settings->get_api_token();
		$api_secret  = $this->settings->get_api_secret();
		$profile_url = KantanBond_Settings::KANTANBIZ_PROFILE_URL;

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
					</tbody>
				</table>

				<?php submit_button( __( '設定を保存', 'kantanbond' ), 'primary', 'kantanbond_settings_submit' ); ?>
			</form>
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
