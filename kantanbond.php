<?php
/**
 * Plugin Name: KantanBond
 * Plugin URI: https://kantanbiz.cloud/
 * Description: WordPress と KantanBiz（KantanBiz Cloud）を API 連携する公式連携プラグインです。
 * Version: 1.3.2
 * Author: KantanPro
 * Author URI: https://www.kantanpro.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kantanbond
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.1
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KANTANBOND_VERSION', '1.3.2' );
define( 'KANTANBOND_PLUGIN_FILE', __FILE__ );
define( 'KANTANBOND_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KANTANBOND_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KANTANBOND_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once KANTANBOND_PLUGIN_DIR . 'includes/class-installer.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-logger.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-settings.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-api.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-public-products.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-admin.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-loader.php';
require_once KANTANBOND_PLUGIN_DIR . 'includes/class-github-updater.php';

/**
 * GitHub Releases 連携の更新通知（管理画面・WP-Cron）。
 *
 * レート制限回避が必要な場合は wp-config.php 等で
 * define( 'KANTANBOND_GITHUB_TOKEN', 'ghp_xxx' ); を定義してください。
 *
 * @return void
 */
function kantanbond_init_github_updater(): void {
	if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	new KantanBond_GitHub_Updater(
		array(
			'plugin_file'  => KANTANBOND_PLUGIN_FILE,
			'plugin_slug'  => 'KantanBond',
			'repo_owner'   => 'KantanPro',
			'repo_name'    => 'kantanbond',
			'requires_wp'  => '6.8',
			'requires_php' => '8.1',
			'tested_wp'    => get_bloginfo( 'version' ),
		)
	);
}

add_action( 'plugins_loaded', 'kantanbond_init_github_updater', 1 );

/**
 * プラグイン有効化フック。
 *
 * @return void
 */
function kantanbond_activate(): void {
	KantanBond_Installer::activate();
}

/**
 * プラグイン無効化フック。
 *
 * @return void
 */
function kantanbond_deactivate(): void {
	KantanBond_Installer::deactivate();
}

register_activation_hook( __FILE__, 'kantanbond_activate' );
register_deactivation_hook( __FILE__, 'kantanbond_deactivate' );

/**
 * プラグインを起動する。
 *
 * @return void
 */
function kantanbond_run(): void {
	$settings   = new KantanBond_Settings();
	$logger     = new KantanBond_Logger();
	$api        = new KantanBond_API( $settings, $logger );
	$shortcodes      = new KantanBond_Shortcodes( $api );
	$public_products = new KantanBond_Public_Products( $api );
	$admin           = new KantanBond_Admin( $settings, $logger, $api );
	$loader          = new KantanBond_Loader( $admin, $shortcodes, $public_products );

	$loader->init();
}

add_action( 'plugins_loaded', 'kantanbond_run' );
