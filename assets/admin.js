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

	// Opening the page at #spl-decide focuses the decline reason.
	if (window.location.hash === '#spl-decide') {
		var $d = $('.spl-decision-decline details');
		$d.attr('open', true);
		$d.find('textarea').trigger('focus');
	}
});
