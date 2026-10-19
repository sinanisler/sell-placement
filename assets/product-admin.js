/* Image Placement product option: shows the tab only while the box is ticked, and opens it on tick. */
jQuery(function ($) {
	var $box = $('#_spl_enabled');
	var $tab = $('.product_data_tabs .spl_placement_tab');
	var $panel = $('#spl_placement_data');

	function isSimple() {
		return $('select#product-type').val() === 'simple';
	}

	function sync(open) {
		var on = $box.is(':checked') && isSimple();
		$tab.toggle(on);
		if (on && open) {
			$tab.find('a').trigger('click');
			// A placement is never shipped, so tick Virtual along with it (it can still be unticked).
			var $virtual = $('#_virtual');
			if (!$virtual.is(':checked')) {
				$virtual.prop('checked', true).trigger('change');
			}
		}
		if (!on && $panel.is(':visible')) {
			$('.product_data_tabs li:visible:first a').trigger('click');
		}
	}

	function ratio() {
		var w = parseInt($('#_spl_min_width').val() || $('#_spl_min_width').attr('placeholder'), 10);
		var h = parseInt($('#_spl_min_height').val() || $('#_spl_min_height').attr('placeholder'), 10);
		if (!(w > 0 && h > 0)) {
			$('.spl-ratio-preview').text('–');
			return;
		}
		var a = w, b = h, t;
		while (b) { t = b; b = a % b; a = t; }
		var label = (w / a > 50 || h / a > 50) ? (w / h).toFixed(2) + ':1' : (w / a) + ':' + (h / a);
		$('.spl-ratio-preview').text(label + '  (' + w + ' × ' + h + ' px)');
	}

	$box.on('change', function () { sync(true); });
	$('select#product-type').on('change', function () { setTimeout(function () { sync(false); }, 0); });
	// WooCommerce re-shows "show_if_simple" tabs on its own init, so settle after it.
	$(document.body).on('woocommerce-product-type-change', function () { sync(false); });
	setTimeout(function () { sync(false); }, 50);

	$('#_spl_min_width, #_spl_min_height').on('input change', ratio);
	ratio();
});
