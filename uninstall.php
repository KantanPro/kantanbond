<?php
/**
 * KantanBond アンインストール処理。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-installer.php';

KantanBond_Installer::uninstall();
