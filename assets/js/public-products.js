(function () {
	'use strict';

	if (typeof window.kantanbondPublicProducts === 'undefined') {
		return;
	}

	var config = window.kantanbondPublicProducts;
	var i18n = config.i18n || {};
	var openModalCount = 0;
	var openLightboxCount = 0;
	var lightboxEl = null;
	var lightboxImageEl = null;
	var lightboxLastActive = null;

	var ZOOMABLE_IMAGE_SELECTOR =
		'.kantanbond-public-products-grid__image,' +
		'.kantanbond-public-products-card__image,' +
		'.kantanbond-public-products-thumb,' +
		'.kantanbond-public-product-detail__image';

	function qs(root, selector) {
		return (root || document).querySelector(selector);
	}

	function qsa(root, selector) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function parseProduct(el) {
		var raw = el.getAttribute('data-product');
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text == null ? '' : String(text);
		return div.innerHTML;
	}

	function formatMultiline(text) {
		return escapeHtml(text).replace(/\n/g, '<br>');
	}

	function lockBodyScroll() {
		openModalCount += 1;
		if (openModalCount === 1) {
			document.body.classList.add('kantanbond-public-product-modal-open');
		}
	}

	function unlockBodyScroll() {
		openModalCount = Math.max(0, openModalCount - 1);
		if (openModalCount === 0) {
			document.body.classList.remove('kantanbond-public-product-modal-open');
		}
	}

	function lockLightboxScroll() {
		openLightboxCount += 1;
		if (openLightboxCount === 1) {
			document.body.classList.add('kantanbond-public-product-lightbox-open');
		}
	}

	function unlockLightboxScroll() {
		openLightboxCount = Math.max(0, openLightboxCount - 1);
		if (openLightboxCount === 0) {
			document.body.classList.remove('kantanbond-public-product-lightbox-open');
		}
	}

	function ensureLightbox() {
		if (lightboxEl) {
			return;
		}

		lightboxEl = document.createElement('div');
		lightboxEl.id = 'kantanbond-public-product-lightbox';
		lightboxEl.className = 'kantanbond-public-product-lightbox';
		lightboxEl.hidden = true;
		lightboxEl.innerHTML =
			'<button type="button" class="kantanbond-public-product-lightbox__backdrop" aria-label="' +
			escapeHtml(i18n.close || '閉じる') +
			'"></button>' +
			'<figure class="kantanbond-public-product-lightbox__figure">' +
			'<button type="button" class="kantanbond-public-product-lightbox__close" aria-label="' +
			escapeHtml(i18n.close || '閉じる') +
			'">&times;</button>' +
			'<img class="kantanbond-public-product-lightbox__image" alt="" decoding="async" />' +
			'</figure>';

		document.body.appendChild(lightboxEl);
		lightboxImageEl = qs(lightboxEl, '.kantanbond-public-product-lightbox__image');

		var backdrop = qs(lightboxEl, '.kantanbond-public-product-lightbox__backdrop');
		var closeBtn = qs(lightboxEl, '.kantanbond-public-product-lightbox__close');
		var figure = qs(lightboxEl, '.kantanbond-public-product-lightbox__figure');

		function onLightboxEscape(event) {
			if (event.key === 'Escape') {
				event.stopImmediatePropagation();
				closeImageLightbox();
			}
		}

		function closeImageLightbox() {
			if (!lightboxEl || lightboxEl.hidden) {
				return;
			}

			lightboxEl.hidden = true;
			lightboxEl.classList.remove('is-open');
			unlockLightboxScroll();
			document.removeEventListener('keydown', onLightboxEscape, true);

			if (lightboxImageEl) {
				lightboxImageEl.removeAttribute('src');
				lightboxImageEl.alt = '';
			}

			if (lightboxLastActive && typeof lightboxLastActive.focus === 'function') {
				lightboxLastActive.focus();
			}
			lightboxLastActive = null;
		}

		function openImageLightbox(src, alt) {
			if (!src || !lightboxImageEl) {
				return;
			}

			lightboxLastActive = document.activeElement;
			lightboxImageEl.src = src;
			lightboxImageEl.alt = alt || '';
			lightboxEl.hidden = false;
			lightboxEl.classList.add('is-open');
			lockLightboxScroll();
			document.addEventListener('keydown', onLightboxEscape, true);

			window.requestAnimationFrame(function () {
				if (closeBtn) {
					closeBtn.focus();
				}
			});
		}

		if (backdrop) {
			backdrop.addEventListener('click', closeImageLightbox);
		}
		if (closeBtn) {
			closeBtn.addEventListener('click', closeImageLightbox);
		}
		if (figure) {
			figure.addEventListener('click', function (event) {
				event.stopPropagation();
			});
		}

		lightboxEl._open = openImageLightbox;
		lightboxEl._close = closeImageLightbox;
	}

	function openImageLightbox(src, alt) {
		ensureLightbox();
		if (lightboxEl && typeof lightboxEl._open === 'function') {
			lightboxEl._open(src, alt);
		}
	}

	function initImageZoom() {
		document.addEventListener(
			'click',
			function (event) {
				var img = event.target.closest(ZOOMABLE_IMAGE_SELECTOR);
				if (!img || img.tagName !== 'IMG' || !img.src) {
					return;
				}

				var inList = img.closest('.kantanbond-public-products');
				var inDetail = img.closest('#kantanbond-public-product-detail');
				if (!inList && !inDetail) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				openImageLightbox(img.src, img.alt || img.getAttribute('alt') || '');
			},
			true
		);
	}

	function buildDetailHtml(product) {
		var parts = [];
		parts.push('<div class="kantanbond-public-product-detail__hero">');
		if (product.image) {
			parts.push(
				'<img src="' +
					escapeHtml(product.image) +
					'" alt="' +
					escapeHtml(product.name) +
					'" class="kantanbond-public-product-detail__image" loading="lazy" decoding="async" />'
			);
		}
		parts.push('<div class="kantanbond-public-product-detail__meta">');
		parts.push('<h3 class="kantanbond-public-product-detail__name">' + escapeHtml(product.name) + '</h3>');
		if (product.category) {
			parts.push(
				'<p class="kantanbond-public-product-detail__category">' +
					escapeHtml(i18n.category || 'カテゴリ') +
					': ' +
					escapeHtml(product.category) +
					'</p>'
			);
		}
		if (product.price_display) {
			parts.push(
				'<p class="kantanbond-public-product-detail__price">' +
					escapeHtml(i18n.price || '単価') +
					': ' +
					escapeHtml(product.price_display) +
					(product.unit ? ' / ' + escapeHtml(product.unit) : '') +
					'</p>'
			);
		}
		if (product.tax_rate !== '' && product.tax_rate != null) {
			parts.push(
				'<p class="kantanbond-public-product-detail__tax">' +
					escapeHtml(i18n.tax || '税率') +
					': ' +
					escapeHtml(product.tax_rate) +
					'%</p>'
			);
		}
		if (product.memo) {
			parts.push(
				'<div class="kantanbond-public-product-detail__memo">' +
					'<span class="kantanbond-public-product-detail__memo-label">' +
					escapeHtml(i18n.memo || 'メモ') +
					'</span>' +
					'<div class="kantanbond-public-product-detail__memo-body">' +
					formatMultiline(product.memo) +
					'</div></div>'
			);
		}
		parts.push('</div></div>');
		return parts.join('');
	}

	function initCategoryFilter(wrapper) {
		var input = qs(wrapper, '.kantanbond-public-products-filter__input');
		if (!input) {
			return;
		}

		var clearBtn = qs(wrapper, '.kantanbond-public-products-filter__clear');
		var emptyMsg = qs(wrapper, '.kantanbond-public-products-filter__empty');
		var items = qsa(wrapper, '.kantanbond-public-product-item');

		function applyFilter() {
			var term = (input.value || '').trim().toLowerCase();
			var visible = 0;

			items.forEach(function (item) {
				var cat = (item.getAttribute('data-category') || '').trim().toLowerCase();
				var show = !term || cat === term || cat.indexOf(term) !== -1;
				item.hidden = !show;
				if (show) {
					visible += 1;
				}
			});

			if (emptyMsg) {
				emptyMsg.hidden = visible > 0;
			}
		}

		input.addEventListener('input', applyFilter);
		input.addEventListener('change', applyFilter);

		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				input.value = '';
				applyFilter();
				input.focus();
			});
		}

		applyFilter();
	}

	function initWrapper(wrapper) {
		initCategoryFilter(wrapper);
		var detail = qs(wrapper, '#kantanbond-public-product-detail');
		if (!detail) {
			return;
		}

		if (detail.parentNode !== document.body) {
			document.body.appendChild(detail);
		}

		var content = qs(detail, '.kantanbond-public-product-detail__content');
		var form = qs(detail, '.kantanbond-public-product-order-form');
		var closeBtn = qs(detail, '.kantanbond-public-product-detail__close');
		var backdrop = qs(detail, '.kantanbond-public-product-detail__backdrop');
		var dialog = qs(detail, '.kantanbond-public-product-detail__panel');
		var messageBox = qs(form, '.kantanbond-public-product-order-form__message');
		var submitBtn = qs(form, '.kantanbond-public-product-order-form__submit');
		var serviceIdInput = qs(form, 'input[name="service_id"]');
		var lastActiveElement = null;

		function onEscapeKey(event) {
			if (event.key === 'Escape') {
				closeDetail();
			}
		}

		function setActiveItem(el) {
			qsa(wrapper, '.kantanbond-public-product-item.is-active').forEach(function (node) {
				node.classList.remove('is-active');
			});
			if (el) {
				el.classList.add('is-active');
			}
		}

		function hideMessage() {
			if (!messageBox) {
				return;
			}
			messageBox.hidden = true;
			messageBox.textContent = '';
			messageBox.className = 'kantanbond-public-product-order-form__message';
		}

		function showMessage(text, type) {
			if (!messageBox) {
				return;
			}
			messageBox.hidden = false;
			messageBox.textContent = text;
			messageBox.className =
				'kantanbond-public-product-order-form__message kantanbond-public-product-order-form__message--' + (type || 'info');
		}

		function openDetail(el) {
			var product = parseProduct(el);
			if (!product || !product.id) {
				return;
			}

			lastActiveElement = document.activeElement;

			hideMessage();
			if (form) {
				form.reset();
				if (serviceIdInput) {
					serviceIdInput.value = String(product.id);
				}
				var qty = qs(form, 'input[name="quantity"]');
				if (qty) {
					qty.value = '1';
				}
				form.hidden = false;
			}

			if (content) {
				content.innerHTML = buildDetailHtml(product);
			}

			detail.hidden = false;
			detail.classList.add('is-open');
			lockBodyScroll();
			setActiveItem(el);

			document.addEventListener('keydown', onEscapeKey);

			window.requestAnimationFrame(function () {
				if (closeBtn) {
					closeBtn.focus();
				}
			});
		}

		function closeDetail() {
			if (detail.hidden) {
				return;
			}

			detail.hidden = true;
			detail.classList.remove('is-open');
			unlockBodyScroll();
			setActiveItem(null);
			hideMessage();
			document.removeEventListener('keydown', onEscapeKey);

			if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
				lastActiveElement.focus();
			}
		}

		qsa(wrapper, '.kantanbond-public-product-item').forEach(function (item) {
			item.addEventListener('click', function () {
				openDetail(item);
			});
			item.addEventListener('keydown', function (event) {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					openDetail(item);
				}
			});
		});

		if (closeBtn) {
			closeBtn.addEventListener('click', closeDetail);
		}

		if (backdrop) {
			backdrop.addEventListener('click', closeDetail);
		}

		if (dialog) {
			dialog.addEventListener('click', function (event) {
				event.stopPropagation();
			});
		}

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				hideMessage();

				if (!serviceIdInput || !serviceIdInput.value) {
					showMessage(i18n.networkError || 'エラー', 'error');
					return;
				}

				var formData = new FormData(form);
				formData.append('action', 'kantanbond_public_product_submit');
				formData.append('nonce', config.nonce);

				if (submitBtn) {
					submitBtn.disabled = true;
					submitBtn.textContent = i18n.submitting || '送信中…';
				}

				fetch(config.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (json) {
						if (json && json.success) {
							showMessage((json.data && json.data.message) || i18n.submit || '送信しました', 'success');
							form.hidden = true;
						} else {
							var errMsg =
								(json && json.data && json.data.message) ||
								i18n.networkError ||
								'送信に失敗しました';
							showMessage(errMsg, 'error');
						}
					})
					.catch(function () {
						showMessage(i18n.networkError || '通信エラー', 'error');
					})
					.finally(function () {
						if (submitBtn) {
							submitBtn.disabled = false;
							submitBtn.textContent = i18n.submit || '送信する';
						}
					});
			});
		}
	}

	function boot() {
		initImageZoom();
		qsa(document, '.kantanbond-public-products').forEach(initWrapper);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
