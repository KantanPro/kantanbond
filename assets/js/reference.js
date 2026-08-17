/**
 * [kantanbond_reference] 章アコーディオン・目次ジャンプ・読み上げ（TTS）
 *
 * 読み上げはブラウザ内蔵の Web Speech API のみを使う（本文を外部へ送らない）。
 * KantanBiz 側（/reference）の実装を 1 ページ版に移植したもの:
 * - 対話 1 行 = 1 発話。話者名を先に読み、キャラごとに声色を変える。
 * - iOS Safari はユーザー操作の外から speak() を呼ぶと無視されるため、開始時に残り全行を積む。
 * - Chrome は長い発話が 15 秒ほどで止まるため、再生中は定期的に resume() する。
 * - KantanBiz は節ごとにページが分かれるが、こちらは 1 ページなので「通し読み」は
 *   ページ遷移せず、閉じている章を開きながら最後の節まで読み進める。
 *
 * @package KantanBond
 */
(function () {
	'use strict';

	var RATE_STORAGE_KEY = 'kantanbond-reference-tts-rate';
	var FONT_STORAGE_KEY = 'kantanbond-reference-font';
	var DEFAULT_RATE = 1;
	var ALLOWED_RATES = [ 0.75, 1, 1.25, 1.5 ];
	var FONT_SIZES = [ 'sm', 'md', 'lg', 'xl' ];
	var DESKTOP_QUERY = '(min-width: 1024px)';

	/** キャラクターごとの声色（同じ声しか無い環境でも聞き分けられるようにする）。 */
	var VOICE_PROFILES = {
		biz: { pitch: 1.35, rateScale: 1.02 },
		hakase: { pitch: 0.75, rateScale: 0.96 },
		note: { pitch: 1, rateScale: 1 }
	};

	/**
	 * @return {boolean} 読み上げに対応しているか。
	 */
	function isSupported() {
		return typeof window !== 'undefined'
			&& 'speechSynthesis' in window
			&& typeof window.SpeechSynthesisUtterance === 'function';
	}

	/**
	 * @return {boolean} アニメーション控えめ設定か。
	 */
	function prefersReducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/**
	 * @param {string} key キー。
	 * @return {string} 保存値（取れなければ空文字）。
	 */
	function readStorage( key ) {
		try {
			return window.localStorage.getItem( key ) || '';
		} catch ( e ) {
			// プライベートモード等では既定値で続行
			return '';
		}
	}

	/**
	 * @param {string} key   キー。
	 * @param {string} value 値。
	 * @return {void}
	 */
	function writeStorage( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {
			// 保存できなくても動作自体は変わらない
		}
	}

	/**
	 * 日本語の声を選ぶ。キャラごとに別の声が取れるならそれを割り当てる。
	 *
	 * @return {{biz: ?SpeechSynthesisVoice, hakase: ?SpeechSynthesisVoice, note: ?SpeechSynthesisVoice}}
	 */
	function pickVoices() {
		var voices = window.speechSynthesis.getVoices() || [];
		var japanese = voices.filter( function ( v ) {
			return ( v.lang || '' ).toLowerCase().indexOf( 'ja' ) === 0;
		} );
		var pool = japanese.length > 0 ? japanese : voices;

		if ( pool.length === 0 ) {
			return { biz: null, hakase: null, note: null };
		}

		var byName = function ( needle ) {
			var found = null;

			pool.forEach( function ( v ) {
				if ( ! found && ( v.name || '' ).toLowerCase().indexOf( needle ) >= 0 ) {
					found = v;
				}
			} );

			return found;
		};

		var biz = byName( 'kyoko' ) || byName( 'o-ren' ) || byName( 'female' ) || pool[ 0 ];
		var other = null;

		pool.forEach( function ( v ) {
			if ( ! other && v !== biz ) {
				other = v;
			}
		} );

		var hakase = byName( 'otoya' ) || byName( 'hattori' ) || byName( 'male' ) || other || biz;

		return { biz: biz, hakase: hakase, note: pool[ 0 ] };
	}

	/**
	 * 節を含む章（details）を開いてから、その節へスクロールする。
	 *
	 * @param {Element} root   ショートコードのラッパー。
	 * @param {string}  target 節の id。
	 * @param {boolean} scroll スクロールするか。
	 * @return {boolean} 節が見つかったか。
	 */
	function revealSection( root, target, scroll ) {
		if ( ! target ) {
			return false;
		}

		var section = document.getElementById( target );

		if ( ! section || ! root.contains( section ) ) {
			return false;
		}

		var chapter = section.closest( 'details.kantanbond-reference__chapter' );

		if ( chapter && ! chapter.open ) {
			chapter.open = true;
		}

		if ( scroll ) {
			section.scrollIntoView( { behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' } );
		}

		return true;
	}

	/**
	 * 1 ページ分の読み上げ。
	 *
	 * @param {Element} root ショートコードのラッパー。
	 * @constructor
	 */
	function ReferenceReader( root ) {
		this.root = root;
		this.items = Array.prototype.slice.call( root.querySelectorAll( '[data-tts-item]' ) );
		this.controls = root.querySelector( '[data-tts-controls]' );
		this.playButton = root.querySelector( '[data-tts-play]' );
		this.pauseButton = root.querySelector( '[data-tts-pause]' );
		this.stopButton = root.querySelector( '[data-tts-stop]' );
		this.rateSelect = root.querySelector( '[data-tts-rate]' );
		this.statusEl = root.querySelector( '[data-tts-status]' );
		this.autopilotButton = root.querySelector( '[data-tts-autopilot]' );
		this.restartButton = root.querySelector( '[data-tts-restart]' );

		this.autopilot = false;
		this.rate = this.loadRate();
		this.voices = { biz: null, hakase: null, note: null };
		this.current = -1;
		this.playing = false;
		this.keepAliveTimer = null;
		// 再生をやり直すたびに増やす世代番号。cancel() で飛んでくる古い発話の
		// error / end イベントを、新しい再生の終了と取り違えないために使う。
		this.session = 0;
		this.startTimer = null;
		// 読み上げ対象の終端（節単位の「読み上げ」では節の最後の行で止める）。
		this.stopAfter = -1;
	}

	/**
	 * @return {number} 保存された読み上げ速度。
	 */
	ReferenceReader.prototype.loadRate = function () {
		var saved = parseFloat( readStorage( RATE_STORAGE_KEY ) );

		return ALLOWED_RATES.indexOf( saved ) >= 0 ? saved : DEFAULT_RATE;
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.init = function () {
		if ( this.items.length === 0 || this.controls === null ) {
			return;
		}

		var self = this;

		this.controls.hidden = false;

		if ( this.rateSelect ) {
			this.rateSelect.value = String( this.rate );
			this.rateSelect.addEventListener( 'change', function () {
				var next = parseFloat( self.rateSelect.value );
				self.rate = ALLOWED_RATES.indexOf( next ) >= 0 ? next : DEFAULT_RATE;
				writeStorage( RATE_STORAGE_KEY, String( self.rate ) );

				// 再生中なら、いまの行から新しい速度で読み直す
				if ( self.playing ) {
					self.start( Math.max( self.current, 0 ), { until: self.stopAfter } );
				}
			} );
		}

		if ( this.playButton ) {
			this.playButton.addEventListener( 'click', function () {
				if ( self.playing && window.speechSynthesis.paused ) {
					self.resume();

					return;
				}

				var from = self.playing && self.current >= 0 ? self.current : self.firstVisibleIndex();
				self.start( from, { until: self.sectionEndIndex( from ) } );
			} );
		}

		if ( this.pauseButton ) {
			this.pauseButton.addEventListener( 'click', function () {
				self.pause();
			} );
		}

		// 停止は通し読みも終わりにする（利用者の明示的な中断なので引きずらない）
		if ( this.stopButton ) {
			this.stopButton.addEventListener( 'click', function () {
				self.setAutopilot( false );
				self.stop();
			} );
		}

		if ( this.autopilotButton ) {
			this.autopilotButton.addEventListener( 'click', function () {
				if ( self.autopilot ) {
					self.setAutopilot( false );
					self.stop();

					return;
				}

				self.setAutopilot( true );

				var from = self.current >= 0 ? self.current : self.firstVisibleIndex();
				self.start( from, { intro: true } );
			} );
		}

		if ( this.restartButton ) {
			this.restartButton.addEventListener( 'click', function () {
				self.setAutopilot( true );
				self.start( 0, { intro: true } );
			} );
		}

		var playButtons = this.root.querySelectorAll( '[data-tts-item-play]' );

		Array.prototype.forEach.call( playButtons, function ( button ) {
			button.hidden = false;
			button.addEventListener( 'click', function () {
				var index = self.indexForButton( button );

				if ( index >= 0 ) {
					self.start( index, { until: self.autopilot ? -1 : self.sectionEndIndex( index ) } );
				}
			} );
		} );

		this.refreshVoices();

		if ( window.speechSynthesis.addEventListener ) {
			window.speechSynthesis.addEventListener( 'voiceschanged', function () {
				self.refreshVoices();
			} );
		}

		this.updateControls();
	};

	/**
	 * 行内・節見出しの再生ボタンから、開始行の index を求める。
	 *
	 * @param {Element} button 再生ボタン。
	 * @return {number} items 内の index（見つからなければ -1）。
	 */
	ReferenceReader.prototype.indexForButton = function ( button ) {
		var item = button.closest( '[data-tts-item]' );

		if ( item ) {
			return this.items.indexOf( item );
		}

		// 節見出しのボタン。その節の最初の行から読む
		var section = button.closest( '[data-tts-section]' );

		if ( ! section ) {
			return -1;
		}

		var first = section.querySelector( '[data-tts-item]' );

		return first ? this.items.indexOf( first ) : -1;
	};

	/**
	 * 開いている章の最初の行（無ければ先頭）。
	 *
	 * @return {number}
	 */
	ReferenceReader.prototype.firstVisibleIndex = function () {
		for ( var i = 0; i < this.items.length; i += 1 ) {
			var chapter = this.items[ i ].closest( 'details.kantanbond-reference__chapter' );

			if ( ! chapter || chapter.open ) {
				return i;
			}
		}

		return 0;
	};

	/**
	 * index の行が属する節の、最後の行の index。
	 *
	 * @param {number} index 開始行。
	 * @return {number}
	 */
	ReferenceReader.prototype.sectionEndIndex = function ( index ) {
		var item = this.items[ index ];

		if ( ! item ) {
			return this.items.length - 1;
		}

		var section = item.closest( '[data-tts-section]' );

		if ( ! section ) {
			return this.items.length - 1;
		}

		var last = index;

		for ( var i = index; i < this.items.length; i += 1 ) {
			if ( this.items[ i ].closest( '[data-tts-section]' ) !== section ) {
				break;
			}

			last = i;
		}

		return last;
	};

	/**
	 * @param {boolean} on 通し読みにするか。
	 * @return {void}
	 */
	ReferenceReader.prototype.setAutopilot = function ( on ) {
		this.autopilot = on;
		this.updateControls();
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.refreshVoices = function () {
		this.voices = pickVoices();
	};

	/**
	 * index 行目から終端までをまとめてキューに積む。
	 *
	 * @param {number} index   開始行。
	 * @param {{intro?: boolean, until?: number}} options intro=true で節見出しを先に読む。until で終端指定（-1 は最後まで）。
	 * @return {void}
	 */
	ReferenceReader.prototype.start = function ( index, options ) {
		options = options || {};

		if ( index < 0 || index >= this.items.length ) {
			return;
		}

		var wasSpeaking = window.speechSynthesis.speaking || window.speechSynthesis.pending;
		var until = typeof options.until === 'number' && options.until >= 0
			? Math.min( options.until, this.items.length - 1 )
			: this.items.length - 1;

		this.session += 1;
		window.speechSynthesis.cancel();
		this.clearHighlight();

		this.playing = true;
		this.current = index;
		this.stopAfter = until;
		this.updateControls();

		// 閉じている章の中から読み始めるときは開いておく（追従スクロールのため）
		this.revealItem( index );

		var self = this;
		var session = this.session;
		var queue = function () {
			if ( session !== self.session ) {
				return;
			}

			if ( options.intro === true ) {
				var intro = self.sectionIntro( index );

				if ( intro !== '' ) {
					window.speechSynthesis.speak( self.buildIntroUtterance( intro, session ) );
				}
			}

			for ( var i = index; i <= until; i += 1 ) {
				window.speechSynthesis.speak( self.buildUtterance( i, session, until ) );
			}

			self.startKeepAlive();
		};

		// Chrome は cancel() 直後の speak() が無視されることがあるため 1 拍おく
		if ( this.startTimer !== null ) {
			window.clearTimeout( this.startTimer );
		}

		this.startTimer = window.setTimeout( queue, wasSpeaking ? 120 : 0 );
	};

	/**
	 * @param {number} index 行。
	 * @return {string} 「章名。節名」（取れなければ空文字）。
	 */
	ReferenceReader.prototype.sectionIntro = function ( index ) {
		var item = this.items[ index ];
		var section = item ? item.closest( '[data-tts-section]' ) : null;

		return section ? ( section.getAttribute( 'data-tts-section-intro' ) || '' ) : '';
	};

	/**
	 * 節見出し（章名・節名）を読む発話。
	 *
	 * @param {string} text    読み上げる見出し。
	 * @param {number} session 世代番号。
	 * @return {SpeechSynthesisUtterance}
	 */
	ReferenceReader.prototype.buildIntroUtterance = function ( text, session ) {
		var self = this;
		var utterance = new window.SpeechSynthesisUtterance( text );

		utterance.lang = 'ja-JP';
		utterance.rate = Math.min( 2, Math.max( 0.5, this.rate ) );
		utterance.pitch = VOICE_PROFILES.note.pitch;

		if ( this.voices.note ) {
			utterance.voice = this.voices.note;
		}

		utterance.addEventListener( 'start', function () {
			if ( session === self.session ) {
				self.updateControls();
			}
		} );

		return utterance;
	};

	/**
	 * @param {number} index   行。
	 * @param {number} session 世代番号。
	 * @param {number} until   この再生の終端行。
	 * @return {SpeechSynthesisUtterance}
	 */
	ReferenceReader.prototype.buildUtterance = function ( index, session, until ) {
		var self = this;
		var item = this.items[ index ];
		var textEl = item.querySelector( '[data-tts-text]' );
		var body = ( textEl ? textEl.textContent : '' ).trim();
		var prefix = ( item.getAttribute( 'data-tts-prefix' ) || '' ).trim();
		var voiceKey = item.getAttribute( 'data-tts-voice' ) || 'note';
		var profile = VOICE_PROFILES[ voiceKey ] || VOICE_PROFILES.note;

		var utterance = new window.SpeechSynthesisUtterance( prefix === '' ? body : prefix + '。' + body );

		utterance.lang = 'ja-JP';
		utterance.rate = Math.min( 2, Math.max( 0.5, this.rate * profile.rateScale ) );
		utterance.pitch = profile.pitch;

		if ( this.voices[ voiceKey ] ) {
			utterance.voice = this.voices[ voiceKey ];
		}

		utterance.addEventListener( 'start', function () {
			if ( session !== self.session ) {
				return;
			}

			self.current = index;
			self.highlight( index );
			self.updateControls();
		} );

		utterance.addEventListener( 'end', function () {
			if ( session === self.session && index === until ) {
				self.handleRunEnd( until );
			}
		} );

		utterance.addEventListener( 'error', function ( event ) {
			// 停止・やり直しによる中断（interrupted / canceled）は通常動作なので無視する
			if ( session !== self.session || event.error === 'interrupted' || event.error === 'canceled' ) {
				return;
			}

			self.stop();
		} );

		return utterance;
	};

	/**
	 * 積んだ分を読み終えたとき。通し読み中なら次の節へ続ける。
	 *
	 * @param {number} until 読み終えた終端行。
	 * @return {void}
	 */
	ReferenceReader.prototype.handleRunEnd = function ( until ) {
		if ( ! this.autopilot || until >= this.items.length - 1 ) {
			var finished = until >= this.items.length - 1;

			this.setAutopilot( false );
			this.stop();

			if ( finished && this.statusEl ) {
				this.statusEl.textContent = '最後の節まで読み終わりました。';
			}

			return;
		}

		this.start( until + 1, { intro: true } );
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.pause = function () {
		if ( ! this.playing ) {
			return;
		}

		window.speechSynthesis.pause();
		this.stopKeepAlive();
		this.updateControls();
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.resume = function () {
		window.speechSynthesis.resume();
		this.startKeepAlive();
		this.updateControls();
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.stop = function () {
		this.session += 1;

		if ( this.startTimer !== null ) {
			window.clearTimeout( this.startTimer );
			this.startTimer = null;
		}

		window.speechSynthesis.cancel();
		this.stopKeepAlive();
		this.playing = false;
		this.current = -1;
		this.stopAfter = -1;
		this.clearHighlight();
		this.updateControls();
	};

	/**
	 * 行が閉じた章の中にあれば開く。
	 *
	 * @param {number} index 行。
	 * @return {void}
	 */
	ReferenceReader.prototype.revealItem = function ( index ) {
		var item = this.items[ index ];

		if ( ! item ) {
			return;
		}

		var chapter = item.closest( 'details.kantanbond-reference__chapter' );

		if ( chapter && ! chapter.open ) {
			chapter.open = true;
		}
	};

	/**
	 * @param {number} index 行。
	 * @return {void}
	 */
	ReferenceReader.prototype.highlight = function ( index ) {
		this.clearHighlight();
		this.revealItem( index );

		var item = this.items[ index ];

		if ( ! item ) {
			return;
		}

		item.classList.add( 'kantanbond-reference__line--reading' );
		item.scrollIntoView( {
			behavior: prefersReducedMotion() ? 'auto' : 'smooth',
			block: 'center'
		} );
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.clearHighlight = function () {
		this.items.forEach( function ( item ) {
			item.classList.remove( 'kantanbond-reference__line--reading' );
		} );
	};

	/** Chrome の「長い発話が途中で止まる」対策。 */
	ReferenceReader.prototype.startKeepAlive = function () {
		var self = this;

		this.stopKeepAlive();
		this.keepAliveTimer = window.setInterval( function () {
			if ( ! self.playing ) {
				return;
			}

			if ( window.speechSynthesis.speaking && ! window.speechSynthesis.paused ) {
				window.speechSynthesis.resume();
			}
		}, 8000 );
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.stopKeepAlive = function () {
		if ( this.keepAliveTimer !== null ) {
			window.clearInterval( this.keepAliveTimer );
			this.keepAliveTimer = null;
		}
	};

	/**
	 * @return {void}
	 */
	ReferenceReader.prototype.updateControls = function () {
		var paused = this.playing && window.speechSynthesis.paused;

		if ( this.playButton ) {
			this.playButton.textContent = paused ? '再開' : ( this.playing ? '再生中' : '読み上げ' );
			this.playButton.disabled = this.playing && ! paused;
		}

		if ( this.pauseButton ) {
			this.pauseButton.disabled = ! this.playing || paused;
		}

		if ( this.stopButton ) {
			this.stopButton.disabled = ! this.playing;
		}

		if ( this.autopilotButton ) {
			this.autopilotButton.textContent = this.autopilot ? '通し読みを止める' : '通し読み';
			this.autopilotButton.setAttribute( 'aria-pressed', this.autopilot ? 'true' : 'false' );
			this.autopilotButton.classList.toggle( 'is-on', this.autopilot );
		}

		if ( this.statusEl ) {
			var suffix = this.autopilot ? '｜通し読み中' : '';

			if ( ! this.playing ) {
				this.statusEl.textContent = this.autopilot ? '停止中｜通し読み中' : '停止中';
			} else if ( paused ) {
				this.statusEl.textContent = '一時停止中（' + ( this.current + 1 ) + ' / ' + this.items.length + '）' + suffix;
			} else {
				this.statusEl.textContent = '読み上げ中（' + ( this.current + 1 ) + ' / ' + this.items.length + '）' + suffix;
			}
		}
	};

	/** 画面上で動いている読み上げ（ページを離れるとき止めるため保持する）。 */
	var readers = [];

	/**
	 * 文字サイズ切り替えを初期化する。
	 *
	 * @param {Element} root ラッパー。
	 * @return {void}
	 */
	function setupFontSize( root ) {
		var main = root.querySelector( '[data-kantanbond-reference-main]' );
		var buttons = root.querySelectorAll( '[data-kantanbond-reference-size]' );

		if ( ! main || buttons.length === 0 ) {
			return;
		}

		var apply = function ( size ) {
			FONT_SIZES.forEach( function ( key ) {
				main.classList.toggle( 'kantanbond-reference__main--font-' + key, key === size );
			} );

			Array.prototype.forEach.call( buttons, function ( button ) {
				var isCurrent = button.getAttribute( 'data-kantanbond-reference-size' ) === size;

				button.classList.toggle( 'is-active', isCurrent );
				button.setAttribute( 'aria-pressed', isCurrent ? 'true' : 'false' );
			} );
		};

		var saved = readStorage( FONT_STORAGE_KEY );

		if ( FONT_SIZES.indexOf( saved ) >= 0 ) {
			apply( saved );
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var size = button.getAttribute( 'data-kantanbond-reference-size' );

				if ( FONT_SIZES.indexOf( size ) < 0 ) {
					return;
				}

				apply( size );
				writeStorage( FONT_STORAGE_KEY, size );
			} );
		} );
	}

	/**
	 * 目次サイドバーの開閉（PC は常時開く／狭い画面は折りたたみ）。
	 *
	 * @param {Element} root ラッパー。
	 * @return {void}
	 */
	function setupSidebar( root ) {
		var panel = root.querySelector( '[data-kantanbond-reference-toc]' );

		if ( ! panel || ! window.matchMedia ) {
			return;
		}

		var query = window.matchMedia( DESKTOP_QUERY );
		var sync = function () {
			panel.open = query.matches;
		};

		sync();

		if ( query.addEventListener ) {
			query.addEventListener( 'change', sync );
		} else if ( query.addListener ) {
			query.addListener( sync );
		}
	}

	/**
	 * ショートコード 1 つ分を初期化する。
	 *
	 * @param {Element} root ラッパー。
	 * @return {void}
	 */
	function setup( root ) {
		if ( root.getAttribute( 'data-kantanbond-reference-ready' ) === '1' ) {
			return;
		}

		root.setAttribute( 'data-kantanbond-reference-ready', '1' );

		var chapters = root.querySelectorAll( 'details.kantanbond-reference__chapter' );
		var button = root.querySelector( '[data-kantanbond-reference-toggle-all]' );
		var i;

		if ( button ) {
			syncToggleLabel( button, chapters );

			button.addEventListener( 'click', function () {
				var open = button.getAttribute( 'aria-expanded' ) !== 'true';

				for ( var j = 0; j < chapters.length; j += 1 ) {
					chapters[ j ].open = open;
				}

				syncToggleLabel( button, chapters );
			} );

			for ( i = 0; i < chapters.length; i += 1 ) {
				chapters[ i ].addEventListener( 'toggle', function () {
					syncToggleLabel( button, chapters );
				} );
			}
		}

		var links = root.querySelectorAll( '.kantanbond-reference__toc-section a[href^="#"]' );

		for ( i = 0; i < links.length; i += 1 ) {
			links[ i ].addEventListener( 'click', function ( event ) {
				var target = this.getAttribute( 'href' ).slice( 1 );

				if ( revealSection( root, target, true ) ) {
					event.preventDefault();

					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', '#' + target );
					}
				}
			} );
		}

		setupSidebar( root );
		setupFontSize( root );

		if ( isSupported() ) {
			var reader = new ReferenceReader( root );
			reader.init();
			readers.push( reader );
		} else {
			var notice = root.querySelector( '[data-tts-unsupported]' );

			if ( notice ) {
				notice.hidden = false;
			}
		}

		// 直接 #slug 付き URL で開かれた場合も、閉じている章を開いてから移動する。
		if ( window.location.hash.length > 1 ) {
			revealSection( root, decodeURIComponent( window.location.hash.slice( 1 ) ), true );
		}
	}

	/**
	 * すべて開く／閉じるボタンの表示を更新する。
	 *
	 * @param {Element}                        button   ボタン。
	 * @param {NodeListOf<HTMLDetailsElement>} chapters 章。
	 * @return {void}
	 */
	function syncToggleLabel( button, chapters ) {
		var allOpen = true;
		var i;

		for ( i = 0; i < chapters.length; i += 1 ) {
			if ( ! chapters[ i ].open ) {
				allOpen = false;
				break;
			}
		}

		button.textContent = allOpen
			? button.getAttribute( 'data-label-close' ) || '閉じる'
			: button.getAttribute( 'data-label-open' ) || '開く';
		button.setAttribute( 'aria-expanded', allOpen ? 'true' : 'false' );
	}

	/**
	 * ページ内のすべてのリファレンスを初期化する。
	 *
	 * @return {void}
	 */
	function init() {
		var roots = document.querySelectorAll( '[data-kantanbond-reference]' );

		for ( var i = 0; i < roots.length; i += 1 ) {
			setup( roots[ i ] );
		}
	}

	/** 画面を離れるときは読み上げもタイマーも必ず止める。 */
	function teardown() {
		readers.forEach( function ( reader ) {
			reader.stop();
		} );

		if ( isSupported() ) {
			window.speechSynthesis.cancel();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Elementor 等で後から挿入される場合に備える。
	document.addEventListener( 'kantanbond:reference:refresh', init );

	window.addEventListener( 'pagehide', teardown );
	window.addEventListener( 'beforeunload', teardown );
})();
