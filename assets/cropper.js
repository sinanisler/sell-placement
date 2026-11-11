/*
 * Placement cropper. The frame has the product's aspect ratio. The person zooms and moves the image
 * inside it; zoom is limited so the cropped area never has fewer source pixels than the minimum size.
 * The original file is sent together with the crop rectangle (in source pixels); the server crops.
 */
(function () {
	'use strict';

	function init(form) {
		var cfg = JSON.parse(form.getAttribute('data-spl-config'));
		var t = cfg.i18n;
		var ratio = cfg.minW / cfg.minH;

		var fileInput = form.querySelector('#spl_file');
		var picker = form.querySelector('[data-spl-picker]');
		var drop = form.querySelector('.spl-drop');
		var cropper = form.querySelector('[data-spl-cropper]');
		var frame = form.querySelector('[data-spl-frame]');
		var canvas = form.querySelector('[data-spl-canvas]');
		var ctx = canvas.getContext('2d');
		var slider = form.querySelector('[data-spl-zoom]');
		var output = form.querySelector('[data-spl-output]');
		var status = form.querySelector('[data-spl-status]');
		var progress = form.querySelector('[data-spl-progress]');
		var submit = form.querySelector('[data-spl-submit]');

		// The certificate name follows the name field until the person types their own.
		var nameInput = form.querySelector('[name="name"]');
		var certInput = form.querySelector('[name="cert_name"]');
		if (nameInput && certInput) {
			var linked = certInput.value === '' || certInput.value === nameInput.value;
			nameInput.addEventListener('input', function () { if (linked) { certInput.value = nameInput.value; } });
			certInput.addEventListener('input', function () { linked = certInput.value === ''; });
		}

		var file = null, img = null, objectUrl = null;
		var nw = 0, nh = 0;      // natural (orientation-corrected) size
		var baseW = 0;           // crop width at zoom 1 (largest frame that fits)
		var maxZoom = 1;
		var zoom = 1;
		var cx = 0, cy = 0;      // crop centre in source pixels
		var busy = false;

		function say(msg, kind) {
			status.textContent = msg || '';
			status.className = 'spl-status' + (msg ? ' is-on' : '') + (kind ? ' is-' + kind : '');
		}

		function cropW() { return baseW / zoom; }
		function cropH() { return cropW() / ratio; }

		function clamp() {
			var w = cropW(), h = cropH();
			cx = Math.min(Math.max(cx, w / 2), nw - w / 2);
			cy = Math.min(Math.max(cy, h / 2), nh - h / 2);
		}

		function rect() {
			var w = cropW(), h = cropH();
			return { x: cx - w / 2, y: cy - h / 2, w: w, h: h };
		}

		function draw() {
			if (!img) { return; }
			var box = frame.getBoundingClientRect();
			var dpr = window.devicePixelRatio || 1;
			var W = Math.max(1, Math.round(box.width * dpr));
			var H = Math.max(1, Math.round(box.height * dpr));
			if (canvas.width !== W || canvas.height !== H) {
				canvas.width = W;
				canvas.height = H;
			}
			var r = rect();
			ctx.imageSmoothingQuality = 'high';
			ctx.clearRect(0, 0, W, H);
			ctx.drawImage(img, r.x, r.y, r.w, r.h, 0, 0, W, H);

			var ow = Math.round(r.w), oh = Math.round(ow / ratio);
			showQuality(ow, oh);
			slider.value = zoom;
		}

		// Orange right at the minimum size, turning green as the crop gets bigger (green from 1.4× the minimum).
		function showQuality(ow, oh) {
			var q = ow / cfg.minW;
			var f = Math.min(1, Math.max(0, (q - 1.02) / (1.4 - 1.02)));
			var color = 'hsl(' + Math.round(28 + f * (135 - 28)) + ', 80%, ' + Math.round(40 - f * 9) + '%)';
			var label = q >= 1.4 ? t.qGood : (q >= 1.15 ? t.qOk : t.qMin);
			output.textContent = '';
			output.appendChild(document.createTextNode(t.output.replace('{w}', ow).replace('{h}', oh) + ' · '));
			var strong = document.createElement('strong');
			strong.className = 'spl-quality';
			strong.style.color = color;
			strong.innerHTML = '<span class="spl-quality-dot" aria-hidden="true"></span>';
			strong.firstChild.style.background = color;
			strong.appendChild(document.createTextNode(label));
			output.appendChild(strong);
		}

		function setZoom(z, px, py) {
			// Zoom around a point of the frame (0..1), so the spot under the cursor stays put.
			z = Math.min(Math.max(z, 1), maxZoom);
			if (px === undefined) { px = 0.5; py = 0.5; }
			var before = rect();
			var sx = before.x + before.w * px, sy = before.y + before.h * py;
			zoom = z;
			var w = cropW(), h = cropH();
			cx = sx - w * px + w / 2;
			cy = sy - h * py + h / 2;
			clamp();
			draw();
		}

		function reset() {
			zoom = 1;
			cx = nw / 2;
			cy = nh / 2;
			clamp();
			draw();
		}

		function showPicker() {
			cropper.hidden = true;
			picker.hidden = false;
			submit.disabled = true;
			file = null;
			img = null;
			if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
			fileInput.value = '';
		}

		function ext(name) {
			var m = /\.([a-z0-9]+)$/i.exec(name || '');
			return m ? m[1].toLowerCase() : '';
		}

		/*
		 * Intake. Phones hand over all sorts of files: HEIC/HEIF from iPhones and many Androids, AVIF, files with an
		 * upper-case or missing extension, and 12–200 megapixel photos. Whatever the browser (or the HEIC converter)
		 * can open is accepted. The server takes only cfg.types, so anything else is turned into a JPEG first, and
		 * every photo larger than the server can handle comfortably is scaled down (never below the minimum size).
		 */
		var MIME_EXT = { 'image/jpeg': 'jpg', 'image/pjpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'image/avif': 'avif', 'image/heic': 'heic', 'image/heif': 'heif', 'image/heic-sequence': 'heic', 'image/heif-sequence': 'heif', 'image/bmp': 'bmp', 'image/tiff': 'tiff' };
		var EXT_ALIAS = { jpe: 'jpg', jfif: 'jpg', jpeg: 'jpg', hif: 'heif', tif: 'tiff' };
		var SERVER_PX = 30000000;   // above this the photo is scaled down before upload (a 48 MP iPhone photo becomes ~30 MP)
		var MAX_PX = 16000000;      // canvas limit of iOS Safari, only for files that have to be redrawn anyway
		var HARD_LIMIT = 250 * 1024 * 1024; // refuse absurd files before trying to decode them
		var seq = 0;

		function canonical(e) { return EXT_ALIAS[e] || e; }
		function serverTakes(e) {
			return cfg.types.indexOf(e) !== -1 || (e === 'jpg' && cfg.types.indexOf('jpeg') !== -1);
		}

		// Reads the first bytes: names and MIME types from phones are often missing or wrong.
		function sniff(f) {
			return f.slice(0, 32).arrayBuffer().then(function (buf) {
				var b = new Uint8Array(buf);
				var s = String.fromCharCode.apply(null, b);
				if (b[0] === 0xFF && b[1] === 0xD8) { return 'jpg'; }
				if (s.slice(1, 4) === 'PNG') { return 'png'; }
				if (s.slice(0, 4) === 'RIFF' && s.slice(8, 12) === 'WEBP') { return 'webp'; }
				if (s.slice(0, 3) === 'GIF') { return 'gif'; }
				if (s.slice(0, 2) === 'BM') { return 'bmp'; }
				if (s.slice(0, 4) === 'II*\0' || s.slice(0, 4) === 'MM\0*') { return 'tiff'; }
				if (s.slice(4, 8) === 'ftyp') {
					var brands = s.slice(8, 32);
					if (/avi[fs]/.test(brands)) { return 'avif'; }
					if (/hei[cxms]|hev[cxms]|mif1|msf1/.test(brands)) { return 'heic'; }
				}
				return '';
			}, function () { return ''; });
		}

		function kindOf(f) {
			return sniff(f).then(function (k) {
				return k || canonical(ext(f.name)) || MIME_EXT[(f.type || '').toLowerCase()] || '';
			});
		}

		function decode(f) {
			return new Promise(function (resolve, reject) {
				var url = URL.createObjectURL(f);
				var im = new Image();
				im.onload = function () {
					if (!im.naturalWidth || !im.naturalHeight) { URL.revokeObjectURL(url); reject(); return; }
					resolve({ im: im, url: url });
				};
				im.onerror = function () { URL.revokeObjectURL(url); reject(); };
				im.src = url;
			});
		}

		// Draws the image at the given scale and returns a JPEG file.
		function toJpeg(im, scale, name) {
			return new Promise(function (resolve, reject) {
				var c = document.createElement('canvas');
				c.width = Math.max(1, Math.round(im.naturalWidth * scale));
				c.height = Math.max(1, Math.round(im.naturalHeight * scale));
				var cx = c.getContext('2d');
				cx.fillStyle = '#fff'; // transparent areas (PNG, GIF) become white, not black
				cx.fillRect(0, 0, c.width, c.height);
				cx.drawImage(im, 0, 0, c.width, c.height);
				c.toBlob(function (b) {
					c.width = c.height = 0; // frees the memory at once on iOS
					if (b) { resolve(new File([b], name.replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg' })); } else { reject(); }
				}, 'image/jpeg', 0.92);
			});
		}

		// The largest scale (≤ 1) that keeps the image within px pixels but never below the minimum size.
		function fitScale(w, h, px) {
			var s = Math.min(1, Math.sqrt(px / (w * h)));
			return Math.min(1, Math.max(s, cfg.minW / w, cfg.minH / h));
		}

		// HEIC in browsers that cannot open it (all but Safari): the converter is downloaded on first use.
		function heicLib(f) {
			if (!cfg.heicLib) { return Promise.reject(); }
			return import(cfg.heicLib).then(function (lib) {
				return lib.heicTo({ blob: f, type: 'image/jpeg', quality: 0.92 });
			}).then(function (b) { return new File([b], (f.name || 'photo').replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg' }); });
		}

		// Resolves with a file the server accepts, already within size, or rejects with an i18n key.
		function normalise(f, kind) {
			var name = f.name || 'photo';
			return decode(f).catch(function () {
				if (kind === 'heic' || kind === 'heif' || kind === '') {
					return heicLib(f).then(function (jpg) { f = jpg; kind = 'jpg'; name = jpg.name; return decode(jpg); });
				}
				throw 'unreadable';
			}).then(function (d) {
				var w = d.im.naturalWidth, h = d.im.naturalHeight;
				var ok = serverTakes(kind) && w * h <= SERVER_PX && f.size <= cfg.maxBytes;
				if (ok) {
					URL.revokeObjectURL(d.url);
					// Give the file a proper extension: the server checks it, and Android often sends none.
					var good = name.replace(/\.[^.]*$/, '') + '.' + kind;
					return good === f.name ? f : new File([f], good, { type: f.type || 'image/' + (kind === 'jpg' ? 'jpeg' : kind) });
				}
				if (!serverTakes('jpg')) { URL.revokeObjectURL(d.url); throw 'badType'; }
				var px = Math.min(SERVER_PX, MAX_PX);
				return toJpeg(d.im, fitScale(w, h, px), name).then(function (jpg) {
					URL.revokeObjectURL(d.url);
					if (jpg.size > cfg.maxBytes) { throw 'tooBig'; }
					return jpg;
				}, function () { URL.revokeObjectURL(d.url); throw 'unreadable'; });
			});
		}

		function load(f) {
			if (!f) { return; }
			say('');
			if (f.size > HARD_LIMIT) { say(t.tooBig, 'err'); return; }
			if (f.type && f.type.indexOf('video/') === 0) { say(t.badType, 'err'); return; }
			var id = ++seq;
			say(t.processing);
			kindOf(f).then(function (kind) {
				return normalise(f, kind);
			}).then(function (ready) {
				if (id === seq) { say(''); show(ready); }
			}, function (key) {
				if (id !== seq) { return; }
				say(t[typeof key === 'string' && t[key] ? key : 'unreadable'], 'err');
				showPicker();
			});
		}

		function show(f) {

			if (objectUrl) { URL.revokeObjectURL(objectUrl); }
			objectUrl = URL.createObjectURL(f);
			var im = new Image();
			im.onload = function () {
				nw = im.naturalWidth;
				nh = im.naturalHeight;
				if (nw < cfg.minW || nh < cfg.minH) {
					say(t.tooSmall.replace('{width}', nw).replace('{height}', nh), 'err');
					showPicker();
					return;
				}
				file = f;
				img = im;
				baseW = Math.min(nw, nh * ratio);
				// Never let the crop drop below the minimum size, and never past the configured limit.
				maxZoom = Math.max(1, Math.min(cfg.maxZoom, baseW / cfg.minW));
				slider.max = maxZoom.toFixed(2);
				slider.disabled = maxZoom <= 1.001;
				picker.hidden = true;
				cropper.hidden = false;
				submit.disabled = false;
				reset();
				frame.focus({ preventScroll: true });
			};
			im.onerror = function () { say(t.unreadable, 'err'); showPicker(); };
			im.src = objectUrl;
		}

		fileInput.addEventListener('change', function () { load(fileInput.files && fileInput.files[0]); });

		['dragenter', 'dragover'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); });
		});
		drop.addEventListener('drop', function (e) {
			if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) { load(e.dataTransfer.files[0]); }
		});

		/* Dragging (mouse, pen, touch) and pinch zoom through pointer events. */
		var pointers = {};
		var pinchDist = 0;

		function pointerCount() { return Object.keys(pointers).length; }

		frame.addEventListener('pointerdown', function (e) {
			if (!img) { return; }
			frame.setPointerCapture(e.pointerId);
			pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
			if (pointerCount() === 2) {
				var p = Object.keys(pointers).map(function (k) { return pointers[k]; });
				pinchDist = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
			}
			frame.classList.add('is-dragging');
		});

		frame.addEventListener('pointermove', function (e) {
			if (!img || !pointers[e.pointerId]) { return; }
			var prev = pointers[e.pointerId];
			var box = frame.getBoundingClientRect();
			if (pointerCount() === 1) {
				var scale = cropW() / box.width;
				cx -= (e.clientX - prev.x) * scale;
				cy -= (e.clientY - prev.y) * scale;
				clamp();
				draw();
			}
			pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
			if (pointerCount() === 2) {
				var p = Object.keys(pointers).map(function (k) { return pointers[k]; });
				var d = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
				if (pinchDist > 0) {
					var mx = ((p[0].x + p[1].x) / 2 - box.left) / box.width;
					var my = ((p[0].y + p[1].y) / 2 - box.top) / box.height;
					setZoom(zoom * d / pinchDist, mx, my);
				}
				pinchDist = d;
			}
		});

		function endPointer(e) {
			delete pointers[e.pointerId];
			pinchDist = 0;
			if (!pointerCount()) { frame.classList.remove('is-dragging'); }
		}
		frame.addEventListener('pointerup', endPointer);
		frame.addEventListener('pointercancel', endPointer);

		frame.addEventListener('wheel', function (e) {
			if (!img) { return; }
			e.preventDefault();
			var box = frame.getBoundingClientRect();
			setZoom(zoom * Math.exp(-e.deltaY * 0.0015), (e.clientX - box.left) / box.width, (e.clientY - box.top) / box.height);
		}, { passive: false });

		frame.addEventListener('keydown', function (e) {
			if (!img) { return; }
			var step = cropW() * 0.02;
			var handled = true;
			switch (e.key) {
				case 'ArrowLeft': cx -= step; break;
				case 'ArrowRight': cx += step; break;
				case 'ArrowUp': cy -= step; break;
				case 'ArrowDown': cy += step; break;
				case '+': case '=': setZoom(zoom * 1.1); return e.preventDefault();
				case '-': case '_': setZoom(zoom / 1.1); return e.preventDefault();
				default: handled = false;
			}
			if (handled) { e.preventDefault(); clamp(); draw(); }
		});

		slider.addEventListener('input', function () { setZoom(parseFloat(slider.value)); });
		form.querySelector('[data-spl-zoom-in]').addEventListener('click', function () { setZoom(zoom * 1.15); });
		form.querySelector('[data-spl-zoom-out]').addEventListener('click', function () { setZoom(zoom / 1.15); });
		form.querySelector('[data-spl-reset]').addEventListener('click', reset);
		form.querySelector('[data-spl-change]').addEventListener('click', function () { showPicker(); fileInput.click(); });

		window.addEventListener('resize', draw);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (busy) { return; }
			if (!file) { say(t.pickFirst, 'err'); return; }
			if (!form.reportValidity()) { return; }

			var r = rect();
			var fd = new FormData();
			fd.append('action', 'spl_upload');
			fd.append('nonce', cfg.nonce);
			fd.append('token', cfg.token);
			fd.append('name', form.querySelector('[name="name"]').value);
			fd.append('email', form.querySelector('[name="email"]').value);
			fd.append('cert_name', certInput ? certInput.value : '');
			Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"]:checked'), function (c) { fd.append(c.name, '1'); });
			fd.append('crop_x', Math.round(r.x));
			fd.append('crop_y', Math.round(r.y));
			fd.append('crop_w', Math.round(r.w));
			fd.append('crop_h', Math.round(Math.round(r.w) / ratio));
			fd.append('image', file, file.name);

			busy = true;
			submit.disabled = true;
			progress.hidden = false;
			var bar = progress.querySelector('span');
			say(t.uploading.replace('{p}', 0));

			var xhr = new XMLHttpRequest();
			xhr.open('POST', cfg.ajaxUrl);
			xhr.upload.onprogress = function (ev) {
				if (!ev.lengthComputable) { return; }
				var pct = Math.round(ev.loaded / ev.total * 100);
				bar.style.width = pct + '%';
				say(pct < 100 ? t.uploading.replace('{p}', pct) : t.processing);
			};
			xhr.onload = function () {
				var res = null;
				try { res = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
				if (res && res.success) {
					// The reloaded page shows the "received" heading at the top. Without this the browser restores
					// the scroll position of the form, so the person lands at the bottom and has to scroll up.
					if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
					window.scrollTo(0, 0);
					window.location.reload();
					return;
				}
				busy = false;
				submit.disabled = false;
				progress.hidden = true;
				say(res && res.data && res.data.message ? res.data.message : (xhr.status === 413 ? t.tooBig : t.failed), 'err');
			};
			xhr.onerror = function () {
				busy = false;
				submit.disabled = false;
				progress.hidden = true;
				say(t.failed, 'err');
			};
			xhr.send(fd);
		});
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('form[data-spl-config]'), init);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
