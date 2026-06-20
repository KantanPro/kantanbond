<?php
/**
 * プラグイン各コンポーネントの初期化を担うローダー。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フック登録と各クラスの init 呼び出しを集約する。
 */
class KantanBond_Loader {

	/**
	 * 管理画面クラス。
	 *
	 * @var KantanBond_Admin
	 */
	private KantanBond_Admin $admin;

	/**
	 * ショートコードクラス。
	 *
	 * @var KantanBond_Shortcodes
	 */
	private KantanBond_Shortcodes $shortcodes;

	/**
	 * 公開商品ショートコード。
	 *
	 * @var KantanBond_Public_Products
	 */
	private KantanBond_Public_Products $public_products;

	/**
	 * 購入サンクスページ。
	 *
	 * @var KantanBond_Public_Purchase_Thank_You
	 */
	private KantanBond_Public_Purchase_Thank_You $purchase_thank_you;

	/**
	 * @param KantanBond_Admin                    $admin              管理画面。
	 * @param KantanBond_Shortcodes               $shortcodes         ショートコード。
	 * @param KantanBond_Public_Products          $public_products    公開商品。
	 * @param KantanBond_Public_Purchase_Thank_You $purchase_thank_you 購入サンクス。
	 */
	public function __construct(
		KantanBond_Admin $admin,
		KantanBond_Shortcodes $shortcodes,
		KantanBond_Public_Products $public_products,
		KantanBond_Public_Purchase_Thank_You $purchase_thank_you
	) {
		$this->admin              = $admin;
		$this->shortcodes         = $shortcodes;
		$this->public_products    = $public_products;
		$this->purchase_thank_you = $purchase_thank_you;
	}

	/**
	 * プラグイン全体を初期化する。
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->admin->init();
		}

		$this->shortcodes->init();
		$this->public_products->init();
		$this->purchase_thank_you->init();
	}

	/**
	 * 翻訳ファイルを読み込む。
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'kantanbond',
			false,
			dirname( KANTANBOND_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
