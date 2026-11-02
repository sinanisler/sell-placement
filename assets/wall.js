/*
 * Public wall ([spl_wall]). Search by code, filter by product, sort and "Load more". The server decides
 * what is shown and which image is highlighted; this file only asks for the next page and places the HTML.
 */
(function () {
	'use strict';

	function init(root) {
		var cfg = JSON.parse(root.getAttribute('data-spl-wall-config') || '{}');
		var form = root.querySelector('[data-spl-wall-form]');
		var code = root.querySelector('[data-spl-wall-code]');
		var clear = root.querySelector('[data-spl-wall-clear]');
		var product = root.querySelector('[data-spl-wall-product]');
		var sort = root.querySelector('[data-spl-wall-sort]');
		var grid = root.querySelector('[data-spl-wall-grid]');
		var more = root.querySelector('[data-spl-wall-more]');
		var status = root.querySelector('[data-spl-wall-status]');
		var state = { code: '', page: 1 };

		function load(page, append) {
			var params = new URLSearchParams({
				action: 'spl_wall',
				sort: sort.value,
				product: cfg.product || (product ? product.value : ''),
				per_page: cfg.perPage,
				page: page,
				code: state.code
			});
			root.setAttribute('aria-busy', 'true');
			fetch(cfg.ajax + '?' + params.toString(), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success) { throw new Error('wall'); }
					var d = res.data;
					if (append) {
						grid.insertAdjacentHTML('beforeend', d.html);
					} else {
						grid.innerHTML = d.html;
					}
					state.page = page;
					more.hidden = !d.has_more;
					if (clear) { clear.hidden = !state.code; }
					status.textContent = state.code && !d.found ? d.message : '';
				})
				.catch(function () {
					status.textContent = cfg.error;
				})
				.then(function () {
					root.removeAttribute('aria-busy');
				});
		}

		if (form && code) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				state.code = code.value.trim();
				load(1, false);
			});
		}
		if (clear) {
			clear.addEventListener('click', function () {
				code.value = '';
				state.code = '';
				load(1, false);
			});
		}
		if (product) {
			product.addEventListener('change', function () { load(1, false); });
		}
		sort.addEventListener('change', function () { load(1, false); });
		more.addEventListener('click', function () { load(state.page + 1, true); });
	}

	Array.prototype.forEach.call(document.querySelectorAll('[data-spl-wall]'), init);
})();
