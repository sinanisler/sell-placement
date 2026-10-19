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
			var q = ow / cfg.minW;
			var ql = q >= 1.5 ? t.qGood : (q >= 1.1 ? t.qOk : t.qMin);
			output.textContent = t.output.replace('{w}', ow).replace('{h}', oh) + ' · ' + t.quality.replace('{q}', ql);
			slider.value = zoom;
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

		var heicExt = cfg.heicLib ? ['heic', 'heif'] : [];
		var seq = 0;

		function load(f) {
			if (!f) { return; }
			say('');
			var e = ext(f.name);
			var heic = heicExt.indexOf(e) !== -1;
			if (!heic && cfg.types.indexOf(e) === -1) { say(t.badType, 'err'); return; }
			if (!heic) { show(f); return; }

			var id = ++seq;
			say(t.processing);
			heicToJpeg(f).then(function (jpg) {
				if (id === seq) { say(''); show(jpg); }
			}, function () {
				if (id === seq) { say(t.unreadable, 'err'); showPicker(); }
			});
		}

		// Browsers refuse very large canvases (iOS Safari about 16.7 million pixels), so huge photos are scaled down first.
		var MAX_PX = 16000000;

		// Safari decodes HEIC natively, so the photo is drawn to a canvas and exported as JPEG.
		function nativeJpeg(f) {
			return new Promise(function (resolve, reject) {
				var url = URL.createObjectURL(f);
				var im = new Image();
				im.onload = function () {
					URL.revokeObjectURL(url);
					var w = im.naturalWidth, h = im.naturalHeight;
					if (!w || !h) { reject(); return; }
					var s = Math.min(1, Math.sqrt(MAX_PX / (w * h)));
					var c = document.createElement('canvas');
					c.width = Math.round(w * s);
					c.height = Math.round(h * s);
					c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
					c.toBlob(function (b) { if (b) { resolve(b); } else { reject(); } }, 'image/jpeg', 0.95);
				};
				im.onerror = function () { URL.revokeObjectURL(url); reject(); };
				im.src = url;
			});
		}

		// Other browsers: the converter is downloaded the first time a HEIC photo is picked.
		function libJpeg(f) {
			return import(cfg.heicLib).then(function (lib) {
				return lib.heicTo({ blob: f, type: 'image/jpeg', quality: 0.95 });
			});
		}

		// The server only accepts JPEG, PNG and WEBP, so HEIC is turned into a JPEG file before the crop.
		function heicToJpeg(f) {
			return nativeJpeg(f).catch(function () { return libJpeg(f); }).then(function (blob) {
				return new File([blob], f.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
			});
		}

		function show(f) {
			if (f.size > cfg.maxBytes) { say(t.tooBig, 'err'); return; }

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
					window.location.reload();
					return;
				}
				busy = false;
				submit.disabled = false;
				progress.hidden = true;
				say(res && res.data && res.data.message ? res.data.message : t.failed, 'err');
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
