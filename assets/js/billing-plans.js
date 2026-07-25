/**
 * [kantanbond_billing_plans] プラン選択 UI
 *
 * @package KantanBond
 */
(function () {
	'use strict';

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
	 * @param {ParentNode} scope
	 */
	function bind(scope) {
		var roots = scope.querySelectorAll('[data-kantanbond-billing-plans]');
		roots.forEach(function (root) {
			if (root.getAttribute('data-interactive') !== '1') {
				return;
			}
			if (root.dataset.kantanbondPlansBound === '1') {
				return;
			}
			root.dataset.kantanbondPlansBound = '1';
			syncSelected(root);
			root.addEventListener('change', function (event) {
				var target = event.target;
				if (!(target instanceof HTMLInputElement) || !target.classList.contains('kantanbond-billing-plans__radio')) {
					return;
				}
				syncSelected(root);
			});
		});
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
