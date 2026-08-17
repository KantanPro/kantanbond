/**
 * [kantanbond_reference] 章アコーディオンの開閉と目次ジャンプ
 *
 * @package KantanBond
 */
(function () {
	'use strict';

	/**
	 * 節を含む章（details）を開いてから、その節へスクロールする。
	 *
	 * @param {Element} root   ショートコードのラッパー。
	 * @param {string}  target 節の id。
	 * @param {boolean} scroll スクロールするか。
	 * @return {boolean} 節が見つかったか。
	 */
	function revealSection(root, target, scroll) {
		if (!target) {
			return false;
		}

		var section = document.getElementById(target);

		if (!section || !root.contains(section)) {
			return false;
		}

		var chapter = section.closest('details');

		if (chapter && !chapter.open) {
			chapter.open = true;
		}

		if (scroll) {
			section.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		return true;
	}

	/**
	 * すべて開く／閉じるボタンの表示を更新する。
	 *
	 * @param {Element}      button   ボタン。
	 * @param {NodeListOf<HTMLDetailsElement>} chapters 章。
	 * @return {void}
	 */
	function syncToggleLabel(button, chapters) {
		var allOpen = true;
		var i;

		for (i = 0; i < chapters.length; i++) {
			if (!chapters[i].open) {
				allOpen = false;
				break;
			}
		}

		button.textContent = allOpen
			? button.getAttribute('data-label-close') || '閉じる'
			: button.getAttribute('data-label-open') || '開く';
		button.setAttribute('aria-expanded', allOpen ? 'true' : 'false');
	}

	/**
	 * ショートコード 1 つ分を初期化する。
	 *
	 * @param {Element} root ラッパー。
	 * @return {void}
	 */
	function setup(root) {
		if (root.getAttribute('data-kantanbond-reference-ready') === '1') {
			return;
		}

		root.setAttribute('data-kantanbond-reference-ready', '1');

		var chapters = root.querySelectorAll('details.kantanbond-reference__chapter');
		var button = root.querySelector('[data-kantanbond-reference-toggle-all]');
		var i;

		if (button) {
			syncToggleLabel(button, chapters);

			button.addEventListener('click', function () {
				var open = button.getAttribute('aria-expanded') !== 'true';

				for (var j = 0; j < chapters.length; j++) {
					chapters[j].open = open;
				}

				syncToggleLabel(button, chapters);
			});

			for (i = 0; i < chapters.length; i++) {
				chapters[i].addEventListener('toggle', function () {
					syncToggleLabel(button, chapters);
				});
			}
		}

		var links = root.querySelectorAll('.kantanbond-reference__toc-section a[href^="#"]');

		for (i = 0; i < links.length; i++) {
			links[i].addEventListener('click', function (event) {
				var target = this.getAttribute('href').slice(1);

				if (revealSection(root, target, true)) {
					event.preventDefault();

					if (window.history && window.history.replaceState) {
						window.history.replaceState(null, '', '#' + target);
					}
				}
			});
		}

		// 直接 #slug 付き URL で開かれた場合も、閉じている章を開いてから移動する。
		if (window.location.hash.length > 1) {
			revealSection(root, decodeURIComponent(window.location.hash.slice(1)), true);
		}
	}

	/**
	 * ページ内のすべてのリファレンスを初期化する。
	 *
	 * @return {void}
	 */
	function init() {
		var roots = document.querySelectorAll('[data-kantanbond-reference]');

		for (var i = 0; i < roots.length; i++) {
			setup(roots[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Elementor 等で後から挿入される場合に備える。
	document.addEventListener('kantanbond:reference:refresh', init);
})();
