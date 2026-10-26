/* Placements admin: presets, compose templates, copy, select-all, confirmations. */
jQuery(function ($) {
	var t = window.splAdmin || {};

	// Preset buttons fill the reason box of their own form (still editable before sending).
	$(document).on('click', '[data-spl-preset]', function () {
		var $ta = $(this).closest('form').find('textarea[name="reason"]');
		$ta.val($(this).data('spl-preset')).trigger('focus');
	});

	// "Write an email": start from a template, filled in for this placement.
	var $detail = $('[data-spl-templates]');
	if ($detail.length) {
		var templates = $detail.data('spl-templates') || {};
		var $form = $('.spl-compose');
		var apply = function () {
			var tpl = templates[$form.find('[data-spl-template]').val()];
			if (!tpl) { return; }
			$form.find('[name="to"]').val(tpl.to);
			$form.find('[name="subject"]').val(tpl.subject);
			$form.find('[name="heading"]').val(tpl.heading);
			var ed = window.tinymce && window.tinymce.get('spl_compose_body');
			if (ed && !ed.isHidden()) {
				ed.setContent(tpl.body);
			}
			$('#spl_compose_body').val(tpl.body);
		};
		$form.on('change', '[data-spl-template]', apply);
		$(document).on('tinymce-editor-init', function (e, ed) {
			if (ed.id === 'spl_compose_body') { apply(); }
		});
		apply();
	}

	$(document).on('click', '[data-spl-copy]', function (e) {
		e.preventDefault();
		var $b = $(this), text = $b.data('spl-copy');
		var done = function () {
			var old = $b.text();
			$b.text(t.copied + ' ✓');
			setTimeout(function () { $b.text(old); }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
		} else {
			var $ta = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
			$ta[0].select();
			document.execCommand('copy');
			$ta.remove();
			done();
		}
	});

	$(document).on('change', '[data-spl-select-all]', function () {
		$('.spl-bulk-form input[name="ids[]"]').prop('checked', this.checked);
	});
	$(document).on('click', '[data-spl-bulk-approve]', function (e) {
		if (!$('.spl-bulk-form input[name="ids[]"]:checked').length || !window.confirm(t.confirmBulk)) {
			e.preventDefault();
		}
	});
	$(document).on('click', '[data-spl-confirm]', function (e) {
		if (!window.confirm(t.confirmDelete)) { e.preventDefault(); }
	});

	/* Settings: color pickers, the border image, presets and placeholders. */
	if ($.fn.wpColorPicker) {
		$('.spl-color').wpColorPicker();
	}

	$(document).on('click', '[data-spl-media-pick]', function (e) {
		e.preventDefault();
		var $box = $(this).closest('[data-spl-media]');
		var frame = wp.media({ title: t.pickImage, button: { text: t.useImage }, library: { type: 'image' }, multiple: false });
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
			$box.find('input[type="hidden"]').val(att.id);
			$box.find('.spl-media-preview').attr('src', url).prop('hidden', false);
			$box.find('[data-spl-media-remove]').prop('hidden', false);
		});
		frame.open();
	});
	$(document).on('click', '[data-spl-media-remove]', function (e) {
		e.preventDefault();
		var $box = $(this).closest('[data-spl-media]');
		$box.find('input[type="hidden"]').val('0');
		$box.find('.spl-media-preview').attr('src', '').prop('hidden', true);
		$(this).prop('hidden', true);
	});

	// "Apply" copies the preset's values into the form; nothing is saved until "Save changes".
	$(document).on('click', '[data-spl-apply-preset]', function () {
		var preset = (t.presets || {})[$('[data-spl-preset-select]').val()];
		if (!preset || !window.confirm(t.confirmPreset)) { return; }
		$.each(preset.values, function (key, value) {
			var $f = $('[name="spl[' + key + ']"]');
			if ($f.is(':checkbox')) {
				$f.prop('checked', !!value);
			} else if ($f.hasClass('spl-color') && $.fn.wpColorPicker) {
				$f.wpColorPicker('color', value);
			} else {
				$f.val(value);
			}
		});
	});

	// Clicking a placeholder inserts it into the field of the same row, at the cursor.
	$(document).on('click', '.spl-tokens code', function () {
		var token = $(this).text();
		var $field = $(this).closest('td').find('textarea, input[type="text"]').first();
		if (!$field.length) { return; }
		var ed = window.tinymce && window.tinymce.get($field.attr('id'));
		if (ed && !ed.isHidden()) {
			ed.focus();
			ed.execCommand('mceInsertContent', false, token);
			return;
		}
		var el = $field[0];
		var start = el.selectionStart != null ? el.selectionStart : el.value.length;
		var end = el.selectionEnd != null ? el.selectionEnd : start;
		el.value = el.value.slice(0, start) + token + el.value.slice(end);
		el.focus();
		el.setSelectionRange(start + token.length, start + token.length);
	});

	// Opening the page at #spl-decide focuses the decline reason.
	if (window.location.hash === '#spl-decide') {
		var $d = $('.spl-decision-decline details');
		$d.attr('open', true);
		$d.find('textarea').trigger('focus');
	}
});
