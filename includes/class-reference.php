<?php
/**
 * [kantanbond_reference] KantanBiz リファレンス（ビズちゃん＆ビズ博士の対話）全文表示。
 *
 * @package KantanBond
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KantanBiz の公開 API（GET /api/v1/reference）から章・節の全文を取得して 1 ページに描画する。
 *
 * 本文はテナントのデータを含まないヘルプ文書のため、API トークン設定が無いサイトでも表示できる。
 * 章は <details> で折りたためるので、全文を 1 ページに置いても目次から目的の節へ飛べる。
 */
class KantanBond_Reference {

	/**
	 * ショートコード名。
	 */
	public const SHORTCODE = 'kantanbond_reference';

	/**
	 * 取得結果のキャッシュ（トランジェント）キー接頭辞。
	 */
	private const TRANSIENT_PREFIX = 'kantanbond_reference_';

	/**
	 * 取得結果のキャッシュキー（応答形式を変えたら v を上げる）。
	 */
	private const TRANSIENT_KEY = self::TRANSIENT_PREFIX . 'v1';

	/**
	 * キャッシュ既定時間（分）。
	 */
	private const DEFAULT_CACHE_MINUTES = 720;

	/**
	 * 話者キー（KantanBiz の ReferenceGuide::SPEAKER_* / BLOCK_TIP に対応）。
	 */
	private const SPEAKER_BIZ = 'biz';

	/**
	 * 話者キー（博士）。
	 */
	private const SPEAKER_HAKASE = 'hakase';

	/**
	 * ヒントブロックのキー。
	 */
	private const BLOCK_TIP = 'tip';

	/**
	 * API クライアント。
	 *
	 * @var KantanBond_API
	 */
	private KantanBond_API $api;

	/**
	 * アセット読み込み済みか。
	 *
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * 同一ページ内での描画回数（id 重複回避用）。
	 *
	 * @var int
	 */
	private static int $instance = 0;

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
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * リファレンスショートコードを描画する。
	 *
	 * @param array<string, string> $atts 属性。
	 * @return string
	 */
	public function render_shortcode( array $atts = array() ): string {
		if ( KantanBond_Frontend_Assets::should_skip_shortcode_during_ajax() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'align'      => 'left',
				'toc'        => 'yes',
				'characters' => 'yes',
				'open'       => 'first',
				'chapters'   => '',
				'slugs'      => '',
				'cache'      => (string) self::DEFAULT_CACHE_MINUTES,
			),
			$atts,
			self::SHORTCODE
		);

		$data = $this->fetch_reference( $this->parse_cache_minutes( (string) $atts['cache'] ) );

		if ( is_wp_error( $data ) ) {
			return $this->render_notice( $data->get_error_message() );
		}

		$characters = isset( $data['characters'] ) && is_array( $data['characters'] ) ? $data['characters'] : array();
		$chapters   = $this->filter_chapters(
			isset( $data['chapters'] ) && is_array( $data['chapters'] ) ? $data['chapters'] : array(),
			$this->parse_int_list( (string) $atts['chapters'] ),
			$this->parse_slug_list( (string) $atts['slugs'] )
		);

		if ( $chapters === array() ) {
			return $this->render_notice( __( '表示できるリファレンスがありません。', 'kantanbond' ) );
		}

		KantanBond_Frontend_Assets::enqueue_public_style();
		$this->enqueue_assets();

		++self::$instance;
		$id_prefix = self::$instance > 1
			? 'kantanbond-ref-' . self::$instance . '-'
			: 'kantanbond-ref-';

		$open_mode  = $this->normalize_open_mode( (string) $atts['open'] );
		$show_toc   = $this->is_yes( (string) $atts['toc'] );
		$show_chars = $this->is_yes( (string) $atts['characters'] ) && $characters !== array();

		// アイコンは発言ごとに使い回すため、SVG の実体は 1 回だけ置いて <use> で参照する。
		$html = $this->render_avatar_sprite( $id_prefix );

		if ( $show_toc ) {
			$html .= $this->render_toc( $chapters, $id_prefix );
		}

		if ( $show_chars ) {
			$html .= $this->render_characters( $characters, $id_prefix );
		}

		$html .= $this->render_chapters( $chapters, $characters, $id_prefix, $open_mode );

		$wrapper_class = KantanBond_Shortcode_Align::merge_classes(
			'kantanbond-reference',
			(string) $atts['align'],
			'kantanbond-reference'
		);

		return '<div class="' . esc_attr( $wrapper_class ) . '" data-kantanbond-reference>' . $html . '</div>';
	}

	/**
	 * リファレンス全文を取得する（トランジェントキャッシュ付き）。
	 *
	 * @param int $cache_minutes キャッシュ時間（分）。0 でキャッシュ無効。
	 * @return array<string, mixed>|WP_Error
	 */
	private function fetch_reference( int $cache_minutes ) {
		if ( $cache_minutes > 0 ) {
			$cached = get_transient( self::TRANSIENT_KEY );

			if ( is_array( $cached ) && isset( $cached['chapters'] ) ) {
				return $cached;
			}
		}

		$data = $this->api->get_reference();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $cache_minutes > 0 ) {
			set_transient( self::TRANSIENT_KEY, $data, $cache_minutes * MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * キャッシュを破棄する（Base URL 変更時など）。
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * 目次を描画する。
	 *
	 * @param array<int, array<string, mixed>> $chapters  章配列。
	 * @param string                           $id_prefix 節 id の接頭辞。
	 * @return string
	 */
	private function render_toc( array $chapters, string $id_prefix ): string {
		$items = '';

		foreach ( $chapters as $chapter ) {
			$sections = $this->chapter_sections( $chapter );

			if ( $sections === array() ) {
				continue;
			}

			$links = '';
			foreach ( $sections as $section ) {
				$slug = $this->section_slug( $section );

				if ( $slug === '' ) {
					continue;
				}

				$links .= '<li class="kantanbond-reference__toc-section"><a href="#' . esc_attr( $id_prefix . $slug ) . '">'
					. esc_html( (string) ( $section['title'] ?? '' ) )
					. '</a></li>';
			}

			$items .= '<li class="kantanbond-reference__toc-chapter">'
				. '<span class="kantanbond-reference__toc-chapter-title">' . esc_html( (string) ( $chapter['title'] ?? '' ) ) . '</span>'
				. '<ul class="kantanbond-reference__toc-sections">' . $links . '</ul>'
				. '</li>';
		}

		return '<nav class="kantanbond-reference__toc" aria-label="' . esc_attr__( 'リファレンス目次', 'kantanbond' ) . '">'
			. '<div class="kantanbond-reference__toc-head">'
			. '<p class="kantanbond-reference__toc-title">' . esc_html__( '目次', 'kantanbond' ) . '</p>'
			. '<button type="button" class="kantanbond-reference__toggle-all" data-kantanbond-reference-toggle-all data-label-open="' . esc_attr__( 'すべて開く', 'kantanbond' ) . '" data-label-close="' . esc_attr__( 'すべて閉じる', 'kantanbond' ) . '">'
			. esc_html__( 'すべて開く', 'kantanbond' )
			. '</button>'
			. '</div>'
			. '<ul class="kantanbond-reference__toc-list">' . $items . '</ul>'
			. '</nav>';
	}

	/**
	 * 登場人物カードを描画する。
	 *
	 * @param array<string, mixed> $characters 登場人物。
	 * @param string               $id_prefix  id の接頭辞。
	 * @return string
	 */
	private function render_characters( array $characters, string $id_prefix ): string {
		$items = '';

		foreach ( $characters as $key => $character ) {
			if ( ! is_array( $character ) ) {
				continue;
			}

			$speaker = self::SPEAKER_HAKASE === (string) $key ? self::SPEAKER_HAKASE : self::SPEAKER_BIZ;

			$items .= '<li class="kantanbond-reference__character">'
				. $this->avatar_svg( $speaker, $id_prefix )
				. '<div class="kantanbond-reference__character-body">'
				. '<p class="kantanbond-reference__character-name">'
				. esc_html( (string) ( $character['name'] ?? '' ) )
				. '<span class="kantanbond-reference__character-role">' . esc_html( (string) ( $character['role'] ?? '' ) ) . '</span>'
				. '</p>'
				. '<p class="kantanbond-reference__character-description">' . esc_html( (string) ( $character['description'] ?? '' ) ) . '</p>'
				. '</div>'
				. '</li>';
		}

		if ( $items === '' ) {
			return '';
		}

		return '<div class="kantanbond-reference__characters">'
			. '<p class="kantanbond-reference__characters-title">' . esc_html__( '登場人物', 'kantanbond' ) . '</p>'
			. '<ul class="kantanbond-reference__characters-list">' . $items . '</ul>'
			. '</div>';
	}

	/**
	 * 章（アコーディオン）と節本文を描画する。
	 *
	 * @param array<int, array<string, mixed>> $chapters   章配列。
	 * @param array<string, mixed>             $characters 登場人物。
	 * @param string                           $id_prefix  節 id の接頭辞。
	 * @param string                           $open_mode  first / all / none。
	 * @return string
	 */
	private function render_chapters( array $chapters, array $characters, string $id_prefix, string $open_mode ): string {
		$html  = '';
		$index = 0;

		foreach ( $chapters as $chapter ) {
			$sections = $this->chapter_sections( $chapter );

			if ( $sections === array() ) {
				continue;
			}

			$is_open = 'all' === $open_mode || ( 'first' === $open_mode && 0 === $index );
			$body    = '';

			foreach ( $sections as $section ) {
				$body .= $this->render_section( $section, $characters, $id_prefix );
			}

			$subtitle = (string) ( $chapter['subtitle'] ?? '' );

			$html .= '<details class="kantanbond-reference__chapter"' . ( $is_open ? ' open' : '' ) . '>'
				. '<summary class="kantanbond-reference__chapter-summary">'
				. '<span class="kantanbond-reference__chapter-title">' . esc_html( (string) ( $chapter['title'] ?? '' ) ) . '</span>'
				. ( '' !== $subtitle ? '<span class="kantanbond-reference__chapter-subtitle">' . esc_html( $subtitle ) . '</span>' : '' )
				. '</summary>'
				. '<div class="kantanbond-reference__chapter-body">' . $body . '</div>'
				. '</details>';

			++$index;
		}

		return '<div class="kantanbond-reference__body">' . $html . '</div>';
	}

	/**
	 * 節ひとつを描画する。
	 *
	 * @param array<string, mixed> $section    節。
	 * @param array<string, mixed> $characters 登場人物。
	 * @param string               $id_prefix  節 id の接頭辞。
	 * @return string
	 */
	private function render_section( array $section, array $characters, string $id_prefix ): string {
		$slug  = $this->section_slug( $section );
		$lead  = (string) ( $section['lead'] ?? '' );
		$lines = isset( $section['lines'] ) && is_array( $section['lines'] ) ? $section['lines'] : array();

		$body = '';
		foreach ( $lines as $line ) {
			if ( is_array( $line ) ) {
				$body .= $this->render_line( $line, $characters, $id_prefix );
			}
		}

		return '<section class="kantanbond-reference__section"'
			. ( '' !== $slug ? ' id="' . esc_attr( $id_prefix . $slug ) . '"' : '' )
			. '>'
			. '<h3 class="kantanbond-reference__section-title">' . esc_html( (string) ( $section['title'] ?? '' ) ) . '</h3>'
			. ( '' !== $lead ? '<p class="kantanbond-reference__section-lead">' . esc_html( $lead ) . '</p>' : '' )
			. '<div class="kantanbond-reference__lines">' . $body . '</div>'
			. '</section>';
	}

	/**
	 * 1 行（発言またはヒント）を描画する。
	 *
	 * @param array<string, mixed> $line       行データ。
	 * @param array<string, mixed> $characters 登場人物。
	 * @param string               $id_prefix  id の接頭辞。
	 * @return string
	 */
	private function render_line( array $line, array $characters, string $id_prefix ): string {
		$speaker = isset( $line['s'] ) ? (string) $line['s'] : self::SPEAKER_BIZ;
		$text    = (string) ( $line['text'] ?? '' );

		if ( self::BLOCK_TIP === $speaker ) {
			$title = (string) ( $line['title'] ?? __( 'ヒント', 'kantanbond' ) );

			return '<div class="kantanbond-reference__tip">'
				. '<p class="kantanbond-reference__tip-title">' . esc_html( $title ) . '</p>'
				. '<p class="kantanbond-reference__tip-text">' . esc_html( $text ) . '</p>'
				. '</div>';
		}

		if ( self::SPEAKER_HAKASE !== $speaker ) {
			$speaker = self::SPEAKER_BIZ;
		}

		$name = isset( $characters[ $speaker ]['name'] ) ? (string) $characters[ $speaker ]['name'] : '';

		// 話者名は直後のテキストにも出るので、アイコンは装飾（aria-hidden）扱いにする。
		return '<div class="kantanbond-reference__line kantanbond-reference__line--' . esc_attr( $speaker ) . '">'
			. $this->avatar_svg( $speaker, $id_prefix )
			. '<div class="kantanbond-reference__line-body">'
			. ( '' !== $name ? '<p class="kantanbond-reference__speaker">' . esc_html( $name ) . '</p>' : '' )
			. '<div class="kantanbond-reference__bubble"><p>' . esc_html( $text ) . '</p></div>'
			. '</div>'
			. '</div>';
	}

	/**
	 * 登場人物アイコンの実体（KantanBiz の reference-avatar と同じ図形）を 1 回だけ出力する。
	 *
	 * 発言ごとに SVG を複製すると全文ページが数百 KB 膨らむため、symbol + use にする。
	 *
	 * @param string $id_prefix id の接頭辞。
	 * @return string
	 */
	private function render_avatar_sprite( string $id_prefix ): string {
		$hakase = '<symbol id="' . esc_attr( $id_prefix . 'avatar-' . self::SPEAKER_HAKASE ) . '" viewBox="0 0 64 64">'
			. '<circle cx="32" cy="32" r="32" fill="#bfdbfe" />'
			. '<ellipse cx="32" cy="35" rx="13" ry="14" fill="#f2d7b8" />'
			. '<path d="M18 31C18 18.5 25 13.5 32 13.5S46 18.5 46 31c-2.5-6.5-7-8.5-14-8.5S20.5 24.5 18 31Z" fill="#eef2f7" />'
			. '<path d="M19 37c0 13.5 6.5 20 13 20s13-6.5 13-20c-2.5 7.5-6.5 10-13 10s-10.5-2.5-13-10Z" fill="#eef2f7" />'
			. '<path d="M25.5 42c2 1.5 4.2 2.2 6.5 2.2S36.5 43.5 38.5 42c-1.5 3-3.9 4.4-6.5 4.4s-5-1.4-6.5-4.4Z" fill="#dfe5ed" />'
			. '<g fill="none" stroke="#1f2937" stroke-width="1.8">'
			. '<circle cx="25.8" cy="34" r="5" fill="#ffffff" fill-opacity="0.55" />'
			. '<circle cx="38.2" cy="34" r="5" fill="#ffffff" fill-opacity="0.55" />'
			. '<path d="M30.8 33.6h2.4" stroke-linecap="round" />'
			. '</g>'
			. '<circle cx="25.8" cy="34.4" r="1.7" fill="#1f2937" />'
			. '<circle cx="38.2" cy="34.4" r="1.7" fill="#1f2937" />'
			. '<g stroke="#cbd5e1" stroke-width="2.2" stroke-linecap="round">'
			. '<path d="M21.6 27.4c1.6-1.1 3.6-1.4 5.6-.9" />'
			. '<path d="M42.4 27.4c-1.6-1.1-3.6-1.4-5.6-.9" />'
			. '</g>'
			. '</symbol>';

		$biz = '<symbol id="' . esc_attr( $id_prefix . 'avatar-' . self::SPEAKER_BIZ ) . '" viewBox="0 0 64 64">'
			. '<circle cx="32" cy="32" r="32" fill="#fbcfe8" />'
			. '<ellipse cx="32" cy="36" rx="17" ry="18" fill="#8b5a3c" />'
			. '<ellipse cx="32" cy="37" rx="13.5" ry="14.5" fill="#fbdfc6" />'
			. '<path d="M18.5 32C19 20.8 25.6 16.5 32 16.5S45 20.8 45.5 32c-2.2-5-6.4-7-10.4-5.4-2.8 1.1-4 1.4-6.6 1-3.6-.6-7.3.6-10 4.4Z" fill="#8b5a3c" />'
			. '<circle cx="26.6" cy="37.2" r="2.1" fill="#3f2a21" />'
			. '<circle cx="37.4" cy="37.2" r="2.1" fill="#3f2a21" />'
			. '<ellipse cx="22.3" cy="41.6" rx="2.6" ry="1.7" fill="#f8a2bd" fill-opacity="0.85" />'
			. '<ellipse cx="41.7" cy="41.6" rx="2.6" ry="1.7" fill="#f8a2bd" fill-opacity="0.85" />'
			. '<path d="M29.2 42.8c1.7 2.1 3.9 2.1 5.6 0" fill="none" stroke="#3f2a21" stroke-width="1.7" stroke-linecap="round" />'
			. '<g fill="#f43f5e">'
			. '<ellipse cx="45" cy="23.5" rx="3.4" ry="2.5" transform="rotate(-18 45 23.5)" />'
			. '<ellipse cx="50.2" cy="22" rx="3.4" ry="2.5" transform="rotate(18 50.2 22)" />'
			. '<circle cx="47.6" cy="22.8" r="1.5" fill="#fb7185" />'
			. '</g>'
			. '</symbol>';

		return '<svg class="kantanbond-reference__sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
			. $hakase . $biz
			. '</svg>';
	}

	/**
	 * スプライトのアイコンを参照する <svg>。
	 *
	 * アイコンの隣には必ず話者名のテキストが出るため、読み上げ対象からは外す。
	 *
	 * @param string $speaker   話者キー（biz / hakase）。
	 * @param string $id_prefix id の接頭辞。
	 * @return string
	 */
	private function avatar_svg( string $speaker, string $id_prefix ): string {
		return '<svg class="kantanbond-reference__avatar kantanbond-reference__avatar--' . esc_attr( $speaker ) . '" viewBox="0 0 64 64" aria-hidden="true" focusable="false">'
			. '<use href="#' . esc_attr( $id_prefix . 'avatar-' . $speaker ) . '"></use>'
			. '</svg>';
	}

	/**
	 * 章・節を属性で絞り込む。
	 *
	 * @param array<int, array<string, mixed>> $chapters       章配列。
	 * @param array<int, int>                  $chapter_numbers 1 始まりの章番号（空なら全章）。
	 * @param array<int, string>               $slugs          節 slug（空なら全節）。
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_chapters( array $chapters, array $chapter_numbers, array $slugs ): array {
		$filtered = array();
		$number   = 0;

		foreach ( $chapters as $chapter ) {
			if ( ! is_array( $chapter ) ) {
				continue;
			}

			++$number;

			if ( $chapter_numbers !== array() && ! in_array( $number, $chapter_numbers, true ) ) {
				continue;
			}

			$sections = $this->chapter_sections( $chapter );

			if ( $slugs !== array() ) {
				$sections = array_values(
					array_filter(
						$sections,
						function ( array $section ) use ( $slugs ): bool {
							return in_array( $this->section_slug( $section ), $slugs, true );
						}
					)
				);
			}

			if ( $sections === array() ) {
				continue;
			}

			$chapter['sections'] = $sections;
			$filtered[]          = $chapter;
		}

		return $filtered;
	}

	/**
	 * 章から節配列を取り出す。
	 *
	 * @param array<string, mixed> $chapter 章。
	 * @return array<int, array<string, mixed>>
	 */
	private function chapter_sections( array $chapter ): array {
		if ( ! isset( $chapter['sections'] ) || ! is_array( $chapter['sections'] ) ) {
			return array();
		}

		$sections = array();

		foreach ( $chapter['sections'] as $section ) {
			if ( is_array( $section ) ) {
				$sections[] = $section;
			}
		}

		return $sections;
	}

	/**
	 * 節の slug を安全な形（英小文字・数字・ハイフン）で返す。
	 *
	 * @param array<string, mixed> $section 節。
	 * @return string
	 */
	private function section_slug( array $section ): string {
		$slug = isset( $section['slug'] ) ? (string) $section['slug'] : '';

		return preg_match( '/\A[a-z0-9-]+\z/', $slug ) === 1 ? $slug : '';
	}

	/**
	 * 管理者にだけ理由を見せる案内文。
	 *
	 * @param string $message メッセージ。
	 * @return string
	 */
	private function render_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<p class="kantanbond-reference kantanbond-reference--notice" role="alert">' . esc_html( $message ) . '</p>';
	}

	/**
	 * CSS / JS を読み込む。
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		$css_path = KANTANBOND_PLUGIN_DIR . 'assets/css/reference.css';
		$js_path  = KANTANBOND_PLUGIN_DIR . 'assets/js/reference.js';
		$css_ver  = is_readable( $css_path ) ? (string) filemtime( $css_path ) : KANTANBOND_VERSION;
		$js_ver   = is_readable( $js_path ) ? (string) filemtime( $js_path ) : KANTANBOND_VERSION;

		wp_enqueue_style(
			'kantanbond-reference',
			KANTANBOND_PLUGIN_URL . 'assets/css/reference.css',
			array( 'kantanbond-public' ),
			$css_ver
		);

		wp_enqueue_script(
			'kantanbond-reference',
			KANTANBOND_PLUGIN_URL . 'assets/js/reference.js',
			array(),
			$js_ver,
			true
		);

		self::$assets_enqueued = true;
	}

	/**
	 * open 属性を正規化する。
	 *
	 * @param string $raw 生値。
	 * @return string first / all / none。
	 */
	private function normalize_open_mode( string $raw ): string {
		$value = sanitize_key( trim( $raw ) );

		return in_array( $value, array( 'first', 'all', 'none' ), true ) ? $value : 'first';
	}

	/**
	 * キャッシュ分数を正規化する。
	 *
	 * @param string $raw 生値。
	 * @return int
	 */
	private function parse_cache_minutes( string $raw ): int {
		$value = trim( $raw );

		if ( $value === '' ) {
			return self::DEFAULT_CACHE_MINUTES;
		}

		if ( in_array( strtolower( $value ), array( 'no', 'off', 'false', '0' ), true ) ) {
			return 0;
		}

		$minutes = (int) $value;

		return $minutes > 0 ? $minutes : self::DEFAULT_CACHE_MINUTES;
	}

	/**
	 * カンマ区切りの整数リストを配列にする。
	 *
	 * @param string $raw 生値（例: 1,2）。
	 * @return array<int, int>
	 */
	private function parse_int_list( string $raw ): array {
		if ( trim( $raw ) === '' ) {
			return array();
		}

		$parts   = preg_split( '/\s*,\s*/', trim( $raw ) );
		$numbers = array();

		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				$number = (int) $part;

				if ( $number > 0 ) {
					$numbers[] = $number;
				}
			}
		}

		return array_values( array_unique( $numbers ) );
	}

	/**
	 * カンマ区切りの slug リストを配列にする。
	 *
	 * @param string $raw 生値（例: welcome,screen）。
	 * @return array<int, string>
	 */
	private function parse_slug_list( string $raw ): array {
		if ( trim( $raw ) === '' ) {
			return array();
		}

		$parts = preg_split( '/\s*,\s*/', trim( $raw ) );
		$slugs = array();

		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				$slug = strtolower( trim( (string) $part ) );

				if ( preg_match( '/\A[a-z0-9-]+\z/', $slug ) === 1 ) {
					$slugs[] = $slug;
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * yes / no 系の属性値を判定する。
	 *
	 * @param string $raw 生値。
	 * @return bool
	 */
	private function is_yes( string $raw ): bool {
		$value = strtolower( trim( $raw ) );

		return in_array( $value, array( 'yes', '1', 'true', 'on' ), true );
	}
}
