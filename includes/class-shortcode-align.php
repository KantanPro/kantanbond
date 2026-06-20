<?php
/**
 * ショートコードの横寄せ（align）属性を正規化する。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * left / center / right（左寄せ・中央寄せ・右寄せ）のクラス名を返す。
 */
class KantanBond_Shortcode_Align {

	/** @var list<string> */
	private const VALUES = array( 'left', 'center', 'right' );

	/**
	 * 属性値を left / center / right に正規化する。
	 *
	 * @param string $raw ショートコード属性の生値。
	 * @return string
	 */
	public static function normalize( string $raw ): string {
		$trimmed = trim( $raw );

		$aliases = array(
			'左'       => 'left',
			'左寄せ'   => 'left',
			'left'     => 'left',
			'中央'     => 'center',
			'中央寄せ' => 'center',
			'center'   => 'center',
			'centre'   => 'center',
			'右'       => 'right',
			'右寄せ'   => 'right',
			'right'    => 'right',
		);

		if ( isset( $aliases[ $trimmed ] ) ) {
			return $aliases[ $trimmed ];
		}

		$key = sanitize_key( $trimmed );
		if ( in_array( $key, self::VALUES, true ) ) {
			return $key;
		}

		return 'left';
	}

	/**
	 * ベースクラスに align 修飾クラスを付与する。
	 *
	 * @param string $base_classes 既存の class 文字列。
	 * @param string $raw_align    属性の生値。
	 * @param string $prefix       修飾クラスの接頭辞（例: kantanbond-public-products）。
	 * @return string
	 */
	public static function merge_classes( string $base_classes, string $raw_align, string $prefix ): string {
		$align = self::normalize( $raw_align );
		if ( $align === 'left' ) {
			return trim( $base_classes );
		}

		return trim( $base_classes . ' ' . $prefix . '--align-' . $align );
	}
}
