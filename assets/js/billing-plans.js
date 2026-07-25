/**
 * [kantanbond_billing_plans] プラン選択 UI（年払｜月払 → Stripe 登録導線）
 *
 * @package KantanBond
 */
(function () {
	'use strict';

	/**
	 * @param {string} url
	 * @param {string} plan
	 * @param {string} interval
	 * @param {boolean} paid
	 * @return {string}
	 */
	function buildRegisterUrl(url, plan, interval, paid) {
		try {
			var u = new URL(url, window.location.origin);
			if (paid) {
				u.searchParams.set('plan', plan);
				u.searchParams.set('interval', interval === 'year' ? 'year' : 'month');
			} else {
				u.searchParams.set('plan', 'free');
				u.searchParams.delete('interval');
			}
			return u.toString();
		} catch (e) {
			var sep = url.indexOf('?') >= 0 ? '&' : '?';
			if (paid) {
				return (
					url +
					sep +
					'plan=' +
					encodeURIComponent(plan) +
					'&interval=' +
					encodeURIComponent(interval === 'year' ? 'year' : 'month')
				);
			}
			return url + sep + 'plan=free';
		}
	}

	/**
	 * @param {HTMLElement} root
	 */
	function syncSelected(root) {
		var radios = root.querySelectorAll('.kantanbond-billing-plans__radio');
		radios.forEach(function (radio) {
			var item = radio.closest('.kantanbond-billing-plans__item');
			if (!item) {
				return;
			}
			item.classList.toggle('is-selected', radio.checked);
		});
	}

	/**
	 * @param {HTMLElement} item
	 * @param {string} registerUrl
	 */
	function syncItemCta(item, registerUrl) {
		var plan = item.getAttribute('data-plan') || '';
		var paid = item.getAttribute('data-paid') === '1';
		var cta = item.querySelector('.kantanbond-billing-plans__cta');
		if (!cta || !plan) {
			return;
		}
		var interval = 'month';
		if (paid) {
			var checked = item.querySelector(
				'.kantanbond-billing-plans__interval-input:checked'
			);
			if (checked && checked.value === 'year') {
				interval = 'year';
			}
		}
		cta.setAttribute('href', buildRegisterUrl(registerUrl, plan, interval, paid));
	}

	/**
	 * @param {HTMLElement} root
	 */
	function syncAllCtas(root) {
		var registerUrl = root.getAttribute('data-register-url') || '';
		if (!registerUrl) {
			return;
		}
		root.querySelectorAll('.kantanbond-billing-plans__item').forEach(function (item) {
			syncItemCta(item, registerUrl);
		});
	}

	/**
	 * おすすめ以外のカード高さを、その中の最大に揃える（余白をチームまで伸ばさない）。
	 *
	 * @param {HTMLElement} root
	 */
	function equalizeSecondaryCards(root) {
		if (root.dataset.kantanbondEqualizing === '1') {
			return;
		}

		var items = root.querySelectorAll(
			'.kantanbond-billing-plans__item:not(.kantanbond-billing-plans__item--recommended)'
		);
		if (items.length < 2) {
			return;
		}

		root.dataset.kantanbondEqualizing = '1';

		items.forEach(function (item) {
			var card = item.querySelector('.kantanbond-billing-plans__card');
			if (card) {
				card.style.minHeight = '';
			}
		});

		var maxHeight = 0;
		items.forEach(function (item) {
			var card = item.querySelector('.kantanbond-billing-plans__card');
			if (!card) {
				return;
			}
			maxHeight = Math.max(maxHeight, card.offsetHeight);
		});

		if (maxHeight > 0) {
			var next = maxHeight + 'px';
			items.forEach(function (item) {
				var card = item.querySelector('.kantanbond-billing-plans__card');
				if (card && card.style.minHeight !== next) {
					card.style.minHeight = next;
				}
			});
		}

		window.requestAnimationFrame(function () {
			delete root.dataset.kantanbondEqualizing;
		});
	}

	/**
	 * @param {HTMLElement} root
	 */
	function layoutRoot(root) {
		equalizeSecondaryCards(root);
	}

	/**
	 * @param {ParentNode} scope
	 */
	function bind(scope) {
		var roots = scope.querySelectorAll('[data-kantanbond-billing-plans]');
		roots.forEach(function (root) {
			if (root.dataset.kantanbondPlansBound === '1') {
				layoutRoot(root);
				return;
			}
			root.dataset.kantanbondPlansBound = '1';

			syncSelected(root);
			syncAllCtas(root);
			layoutRoot(root);

			root.addEventListener('change', function (event) {
				var target = event.target;
				if (!(target instanceof HTMLInputElement)) {
					return;
				}
				if (target.classList.contains('kantanbond-billing-plans__radio')) {
					syncSelected(root);
				}
				if (
					target.classList.contains('kantanbond-billing-plans__radio') ||
					target.classList.contains('kantanbond-billing-plans__interval-input')
				) {
					syncAllCtas(root);
				}
			});

			if (typeof ResizeObserver !== 'undefined') {
				var ro = new ResizeObserver(function () {
					layoutRoot(root);
				});
				ro.observe(root);
			}
		});

		if (!window.__kantanbondBillingPlansResizeBound) {
			window.__kantanbondBillingPlansResizeBound = true;
			window.addEventListener('resize', function () {
				document
					.querySelectorAll('[data-kantanbond-billing-plans]')
					.forEach(function (root) {
						layoutRoot(root);
					});
			});
		}
	}

	function init() {
		bind(document);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	document.addEventListener('kantanbond:rebind', init);
})();
