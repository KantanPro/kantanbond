<?php
/**
 * GitHub Releases 連携によるプラグイン更新通知・自動更新。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub リリースを WordPress の更新 API に載せる。
 */
class KantanBond_GitHub_Updater {

	/**
	 * メインプラグインファイルのパス。
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * plugin_basename() の値。
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * プラグインディレクトリ名（展開後リネーム先）。
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * GitHub リポジトリオーナー。
	 *
	 * @var string
	 */
	private string $repo_owner;

	/**
	 * GitHub リポジトリ名。
	 *
	 * @var string
	 */
	private string $repo_name;

	/**
	 * 必要 WordPress バージョン。
	 *
	 * @var string
	 */
	private string $requires_wp;

	/**
	 * 必要 PHP バージョン。
	 *
	 * @var string
	 */
	private string $requires_php;

	/**
	 * テスト済み WordPress バージョン。
	 *
	 * @var string
	 */
	private string $tested_wp;

	/**
	 * @param array<string, string> $args 設定。
	 */
	public function __construct( array $args ) {
		$this->plugin_file     = $args['plugin_file'] ?? KANTANBOND_PLUGIN_FILE;
		$this->plugin_basename = plugin_basename( $this->plugin_file );
		$this->plugin_slug     = $args['plugin_slug'] ?? dirname( $this->plugin_basename );
		$this->repo_owner      = $args['repo_owner'];
		$this->repo_name       = $args['repo_name'];
		$this->requires_wp     = $args['requires_wp'] ?? '6.8';
		$this->requires_php    = $args['requires_php'] ?? '8.1';
		$this->tested_wp       = $args['tested_wp'] ?? get_bloginfo( 'version' );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_install', array( $this, 'before_update' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'rename_github_source' ), 9, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_update' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'upgrader_pre_download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'handle_auto_activation' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_reload_admin_after_activation' ) );
	}

	/**
	 * 更新情報を transients に反映する。
	 *
	 * @param object|false|null $transient update_plugins transient。
	 * @return object|false|null
	 */
	public function check_for_updates( $transient ) {
		if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return $transient;
		}

		if ( $transient === null ) {
			$transient = new stdClass();
		}

		if ( ! isset( $transient->checked ) ) {
			$transient->checked = array();
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data     = get_plugin_data( $this->plugin_file, false, false );
		$current_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '0.0.0';

		$transient->checked[ $this->plugin_basename ] = $current_version;

		$latest = $this->get_latest_version();
		if ( ! $latest || empty( $latest['version'] ) ) {
			return $transient;
		}

		if ( version_compare( $current_version, $latest['version'], '<' ) ) {
			if ( ! isset( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $this->plugin_basename ] = (object) array(
				'id'           => $this->plugin_slug,
				'slug'         => $this->plugin_slug,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $latest['version'],
				'url'          => 'https://github.com/' . $this->repo_owner . '/' . $this->repo_name,
				'package'      => $latest['download_url'],
				'requires'     => $this->requires_wp,
				'requires_php' => $this->requires_php,
				'tested'       => $this->tested_wp,
				'last_updated' => $latest['published_at'],
				'sections'     => array(
					'description' => $latest['description'],
					'changelog'   => $latest['changelog'],
				),
			);

			if ( isset( $transient->no_update[ $this->plugin_basename ] ) ) {
				unset( $transient->no_update[ $this->plugin_basename ] );
			}
		} else {
			if ( ! isset( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$transient->no_update[ $this->plugin_basename ] = (object) array(
				'id'          => $this->plugin_slug,
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $current_version,
				'url'         => 'https://github.com/' . $this->repo_owner . '/' . $this->repo_name,
				'package'     => '',
			);

			if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
				unset( $transient->response[ $this->plugin_basename ] );
			}
		}

		return $transient;
	}

	/**
	 * プラグイン詳細モーダル用の情報を返す。
	 *
	 * @param false|object|array<string, mixed> $result 既存結果。
	 * @param string                            $action plugins_api アクション。
	 * @param object                            $args   リクエスト引数。
	 * @return false|object|array<string, mixed>
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || strtolower( (string) $args->slug ) !== strtolower( $this->plugin_slug ) ) {
			return $result;
		}

		$latest = $this->get_latest_version();
		if ( ! $latest ) {
			return $result;
		}

		$info                  = new stdClass();
		$info->name            = 'KantanBond';
		$info->slug            = $this->plugin_slug;
		$info->version         = $latest['version'];
		$info->last_updated    = $latest['published_at'];
		$info->requires        = $this->requires_wp;
		$info->requires_php    = $this->requires_php;
		$info->tested          = $this->tested_wp;
		$info->download_link   = $latest['download_url'];
		$info->sections        = array(
			'description' => $latest['description'],
			'changelog'   => $latest['changelog'],
		);

		return $info;
	}

	/**
	 * GitHub からのダウンロード前に HTTP 引数を調整する。
	 *
	 * @param bool|string|\WP_Error $reply   既存応答。
	 * @param string                $package パッケージ URL。
	 * @param \WP_Upgrader          $upgrader アップグレーダー。
	 * @return bool|string|\WP_Error
	 */
	public function upgrader_pre_download( $reply, string $package, $upgrader ) {
		unset( $upgrader );

		if ( strpos( $package, 'github.com' ) !== false ) {
			add_filter( 'http_request_args', array( $this, 'github_download_args' ), 10, 2 );
		}

		return $reply;
	}

	/**
	 * GitHub API / ZIP ダウンロード用 HTTP ヘッダーを付与する。
	 *
	 * @param array<string, mixed> $args リクエスト引数。
	 * @param string               $url  URL。
	 * @return array<string, mixed>
	 */
	public function github_download_args( array $args, string $url ): array {
		if ( strpos( $url, 'github.com' ) !== false ) {
			$args['timeout'] = 60;
			$args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' );

			$token = $this->get_github_token();
			if ( $token !== '' ) {
				$args['headers']['Authorization'] = 'Bearer ' . $token;
			}
		}

		return $args;
	}

	/**
	 * 更新前に有効化状態を保存し、一時無効化する。
	 *
	 * @param bool|\WP_Error $response   応答。
	 * @param array<string, mixed> $hook_extra フック引数。
	 * @param mixed            $result   結果。
	 * @return bool|\WP_Error
	 */
	public function before_update( $response, array $hook_extra, $result = null ) {
		unset( $result );

		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_basename ) {
			$was_network_active = is_multisite() && is_plugin_active_for_network( $this->plugin_basename );
			$was_active         = is_plugin_active( $this->plugin_basename ) || $was_network_active;

			set_site_transient(
				$this->transient_key( 'pre_update_state' ),
				array(
					'was_active'     => $was_active,
					'network_active' => $was_network_active,
				),
				30 * MINUTE_IN_SECONDS
			);

			if ( $was_active ) {
				deactivate_plugins( $this->plugin_basename, true, $was_network_active );
			}
		}

		return $response;
	}

	/**
	 * GitHub zipball 展開後のフォルダ名を KantanBond にリネームする。
	 *
	 * @param bool|\WP_Error       $response   応答。
	 * @param array<string, mixed> $hook_extra フック引数。
	 * @param array<string, mixed> $result     インストール結果。
	 * @return bool|\WP_Error|array<string, mixed>
	 */
	public function rename_github_source( $response, array $hook_extra, $result ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $response;
		}

		if ( empty( $result ) || empty( $result['destination'] ) || empty( $result['source'] ) ) {
			return $response;
		}

		$destination  = trailingslashit( (string) $result['destination'] );
		$source       = trailingslashit( (string) $result['source'] );
		$expected_dir = trailingslashit( WP_PLUGIN_DIR ) . $this->plugin_slug . '/';

		if ( untrailingslashit( $destination ) === untrailingslashit( $expected_dir ) ) {
			return $response;
		}

		if ( strpos( basename( $source ), $this->plugin_slug ) === 0 ) {
			if ( is_dir( $expected_dir ) ) {
				$this->rmdir_recursive( $expected_dir );
			}

			// phpcs:ignore WordPress.PHP.NoSilencedFunctions.Discouraged
			@rename( $source, $expected_dir );
			$result['destination'] = $expected_dir;
			$response              = $result;
		}

		return $response;
	}

	/**
	 * 更新後にキャッシュをクリアする。
	 *
	 * @param bool|\WP_Error       $response   応答。
	 * @param array<string, mixed> $hook_extra フック引数。
	 * @param array<string, mixed> $result     インストール結果。
	 * @return bool|\WP_Error|array<string, mixed>
	 */
	public function after_update( $response, array $hook_extra, $result ) {
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_basename ) {
			delete_transient( $this->transient_key( 'latest_version' ) );
			delete_transient( $this->transient_key( 'latest_version_backup' ) );
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_plugins_checked' );
			wp_clean_plugins_cache();

			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}

		return $response;
	}

	/**
	 * 更新完了後にプラグインを再有効化する。
	 *
	 * @param \WP_Upgrader              $upgrader_object アップグレーダー。
	 * @param array<string, mixed>      $options         オプション。
	 * @return void
	 */
	public function handle_auto_activation( $upgrader_object, array $options ): void {
		unset( $upgrader_object );

		if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
			return;
		}

		if ( ! isset( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}

		if ( ! in_array( $this->plugin_basename, $options['plugins'], true ) ) {
			return;
		}

		$was = get_site_transient( $this->transient_key( 'pre_update_state' ) );

		if ( $was && ! empty( $was['was_active'] ) && ! is_plugin_active( $this->plugin_basename ) ) {
			if ( ! empty( $was['network_active'] ) ) {
				activate_plugin( $this->plugin_basename, '', true );
			} else {
				activate_plugin( $this->plugin_basename );
			}
		}

		set_transient( $this->transient_key( 'admin_reload' ), 1, 5 * MINUTE_IN_SECONDS );
		delete_site_transient( $this->transient_key( 'pre_update_state' ) );
	}

	/**
	 * 再有効化後に管理画面を安全にリロードする。
	 *
	 * @return void
	 */
	public function maybe_reload_admin_after_activation(): void {
		if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$needs = get_transient( $this->transient_key( 'admin_reload' ) );
		if ( ! $needs ) {
			return;
		}

		if ( ! isset( $_GET['kantanbond_reloaded'] ) ) {
			$url = add_query_arg( 'kantanbond_reloaded', '1' );
			if ( $url ) {
				wp_safe_redirect( $url );
				exit;
			}
		}

		delete_transient( $this->transient_key( 'admin_reload' ) );
	}

	/**
	 * GitHub Releases から最新版情報を取得する。
	 *
	 * @return array<string, mixed>|false
	 */
	private function get_latest_version() {
		$force_refresh = is_admin() && isset( $_GET['force-check'] ) && $_GET['force-check'] === '1';

		if ( ! $force_refresh ) {
			$cached = get_transient( $this->transient_key( 'latest_version' ) );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		$headers = array(
			'User-Agent'    => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			'Accept'        => 'application/vnd.github.v3+json',
			'Cache-Control' => 'no-cache',
		);

		$token = $this->get_github_token();
		if ( $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$latest_url = 'https://api.github.com/repos/' . $this->repo_owner . '/' . $this->repo_name . '/releases/latest';
		$response   = wp_remote_get(
			$latest_url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		$data = null;
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}

		if ( ! $data || ! isset( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			$list_url = 'https://api.github.com/repos/' . $this->repo_owner . '/' . $this->repo_name . '/releases';
			$resp2    = wp_remote_get(
				$list_url,
				array(
					'timeout' => 15,
					'headers' => $headers,
				)
			);

			if ( ! is_wp_error( $resp2 ) && wp_remote_retrieve_response_code( $resp2 ) === 200 ) {
				$list = json_decode( wp_remote_retrieve_body( $resp2 ), true );
				if ( is_array( $list ) ) {
					foreach ( $list as $rel ) {
						if ( ! is_array( $rel ) || ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
							continue;
						}
						if ( isset( $rel['tag_name'] ) ) {
							$data = $rel;
							break;
						}
					}
				}
			}
		}

		if ( ! $data || ! isset( $data['tag_name'] ) ) {
			$old_cached = get_transient( $this->transient_key( 'latest_version_backup' ) );
			if ( $old_cached !== false ) {
				return $old_cached;
			}

			return false;
		}

		$normalized_version = ltrim( (string) $data['tag_name'], 'v' );
		$download_url       = isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';

		if ( isset( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( ! preg_match( '/\.zip$/i', (string) $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( ! empty( $asset['name'] ) && stripos( (string) $asset['name'], $this->plugin_slug ) !== false ) {
					$download_url = (string) $asset['browser_download_url'];
					break;
				}
				$download_url = (string) $asset['browser_download_url'];
			}
		}

		$changelog = $this->get_changelog_for_version( $normalized_version );
		if ( $changelog === '' && ! empty( $data['body'] ) ) {
			$changelog = (string) $data['body'];
		}

		$version_info = array(
			'version'      => $normalized_version,
			'download_url' => $download_url,
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'description'  => ! empty( $data['body'] ) ? (string) $data['body'] : '',
			'changelog'    => $changelog,
			'prerelease'   => ! empty( $data['prerelease'] ),
			'draft'        => ! empty( $data['draft'] ),
		);

		set_transient( $this->transient_key( 'latest_version' ), $version_info, 15 * MINUTE_IN_SECONDS );
		set_transient( $this->transient_key( 'latest_version_backup' ), $version_info, DAY_IN_SECONDS );

		return $version_info;
	}

	/**
	 * readme.txt の Changelog から該当バージョンを抽出する。
	 *
	 * @param string $version バージョン番号。
	 * @return string
	 */
	private function get_changelog_for_version( string $version ): string {
		$readme_file = dirname( $this->plugin_file ) . '/readme.txt';
		if ( ! is_readable( $readme_file ) ) {
			return '';
		}

		$content = file_get_contents( $readme_file );
		if ( ! is_string( $content ) || $content === '' ) {
			return '';
		}

		$pattern = '/= ' . preg_quote( $version, '/' ) . ' =(.*?)(?== \d|$)/s';
		if ( preg_match( $pattern, $content, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * GitHub トークンを取得する（レート制限回避用・任意）。
	 *
	 * @return string
	 */
	private function get_github_token(): string {
		if ( defined( 'KANTANBOND_GITHUB_TOKEN' ) && KANTANBOND_GITHUB_TOKEN ) {
			return (string) KANTANBOND_GITHUB_TOKEN;
		}

		if ( defined( 'KP_GITHUB_TOKEN' ) && KP_GITHUB_TOKEN ) {
			return (string) KP_GITHUB_TOKEN;
		}

		return '';
	}

	/**
	 * transient キーを生成する。
	 *
	 * @param string $suffix サフィックス。
	 * @return string
	 */
	private function transient_key( string $suffix ): string {
		return 'kantanbond_upd_' . md5( $this->plugin_basename ) . '_' . $suffix;
	}

	/**
	 * ディレクトリを再帰削除する。
	 *
	 * @param string $dir パス。
	 * @return void
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedFunctions.Discouraged
				@unlink( $path );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedFunctions.Discouraged
		@rmdir( $dir );
	}
}
