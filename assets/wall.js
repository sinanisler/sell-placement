/*
 * Public wall ([spl_wall]). Search by code, filter by product, sort and "Load more". The server decides
 * what is shown and which image is highlighted; this file only asks for the next page and places the HTML.
 */
(function () {
	'use strict';

	/*
	 * One lightbox per page, made the first time an image is opened. It shows the clicked picture at once (the
	 * tile's copy is already in memory) and swaps in the full file only when the browser has decoded it, so an
	 * earlier picture never shows. Buttons, arrow keys and swipes step through the tiles of the wall.
	 */
	var box = null;

	function button(cls, label) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = cls;
		b.title = label;
		b.setAttribute('aria-label', label);
		return b;
	}

	function makeLightbox(cfg) {
		var dialog = document.createElement('dialog');
		dialog.className = 'spl-lightbox';
		dialog.setAttribute('data-spl-lightbox', '');
		var img = document.createElement('img');
		var prev = button('spl-lightbox-prev', cfg.prev);
		var next = button('spl-lightbox-next', cfg.next);
		dialog.appendChild(button('spl-lightbox-close', cfg.close));
		dialog.appendChild(prev);
		dialog.appendChild(next);
		dialog.appendChild(img);
		document.body.appendChild(dialog);
		box = { dialog: dialog, img: img, prev: prev, next: next, grid: null, tile: null, seq: 0 };

		// A click on the picture or the backdrop closes it; the arrows stop the click so they do not.
		dialog.addEventListener('click', function () { dialog.close(); });
		prev.addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
		next.addEventListener('click', function (e) { e.stopPropagation(); step(1); });
		// On the document, not the dialog: a step to the last image hides the arrow that had focus, and the keys must keep working.
		document.addEventListener('keydown', function (e) {
			if (!dialog.open) { return; }
			if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
			if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
		});
		var startX = null;
		dialog.addEventListener('touchstart', function (e) {
			startX = e.touches.length === 1 ? e.touches[0].clientX : null;
		}, { passive: true });
		dialog.addEventListener('touchend', function (e) {
			if (startX === null) { return; }
			var dx = e.changedTouches[0].clientX - startX;
			startX = null;
			if (Math.abs(dx) > 50) { step(dx < 0 ? 1 : -1); }
		});
		// However it is closed, a full image still on its way must not be shown later.
		dialog.addEventListener('close', function () { box.seq++; });
		return box;
	}

	/* The tiles of the wall the lightbox was opened from, in on-screen order. Load more adds to the end. */
	function tiles() {
		return box.grid ? Array.prototype.slice.call(box.grid.querySelectorAll('[data-spl-wall-zoom]')) : [];
	}

	function warm(tile) {
		if (tile) {
			var pic = new Image();
			pic.src = tile.querySelector('img').src;
		}
	}

	function decoded(img) {
		if (img.decode) { return img.decode(); }
		return new Promise(function (ok, fail) { img.onload = ok; img.onerror = fail; });
	}

	function show(tile) {
		var seq = ++box.seq; // only the newest request may change the picture
		var thumb = tile.querySelector('img');
		var full = tile.getAttribute('data-full');
		var list = tiles();
		var i = list.indexOf(tile);
		box.tile = tile;
		box.img.alt = thumb.alt;
		// The box takes the picture's shape and the full file's width, so the swap does not move anything.
		box.img.style.removeProperty('--spl-cap');
		if (thumb.naturalWidth) {
			box.img.style.setProperty('--spl-ratio', String(thumb.naturalWidth / thumb.naturalHeight));
		} else {
			box.img.style.removeProperty('--spl-ratio');
		}
		box.img.classList.add('is-loading');
		box.img.src = thumb.src;
		box.prev.hidden = i <= 0;
		box.next.hidden = i < 0 || i >= list.length - 1;
		warm(list[i - 1]);
		warm(list[i + 1]);

		var pic = new Image();
		pic.src = full;
		decoded(pic).then(function () {
			if (seq !== box.seq) { return; }
			box.img.style.setProperty('--spl-cap', pic.naturalWidth + 'px');
			box.img.src = full;
			box.img.classList.remove('is-loading');
		}, function () {
			if (seq === box.seq) { box.img.classList.remove('is-loading'); }
		});
	}

	function step(dir) {
		var list = tiles();
		var target = list[list.indexOf(box.tile) + dir];
		if (target) { show(target); }
	}

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
		grid.addEventListener('click', function (e) {
			var zoom = e.target.closest('[data-spl-wall-zoom]');
			if (!zoom) { return; }
			if (!box) { makeLightbox(cfg); }
			box.grid = grid;
			show(zoom);
			if (!box.dialog.open) { box.dialog.showModal(); }
		});
	}

	Array.prototype.forEach.call(document.querySelectorAll('[data-spl-wall]'), init);
})();
