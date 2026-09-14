/**
 * BLT Events - Settings screen behaviour.
 *
 * - Payments tab: highlights the default provider card, and shows the
 *   settings panel for every provider that is switched on. Several can be
 *   enabled at once, so panel visibility follows the enable checkboxes
 *   rather than the default-provider radio.
 * - Shortcodes tab: copy-to-clipboard buttons.
 */
(function ($) {
	'use strict';

	$(function () {
		// --- Payment provider selection ---
		var $providerRadios = $('input[name="blt_events_payment_provider"]');
		var $cards = $providerRadios.closest('.blt-select-card');
		var $enableBoxes = $('input[name="blt_events_enabled_providers[]"]');

		function enabledProviders() {
			var enabled = [];

			$enableBoxes.filter(':checked').each(function () {
				enabled.push($(this).val());
			});

			// The default is always usable, whether or not it was ticked —
			// this mirrors the same rule on the PHP side.
			var current = $providerRadios.filter(':checked').val();
			if (current && current !== 'none' && enabled.indexOf(current) === -1) {
				enabled.push(current);
			}

			return enabled;
		}

		function syncProviderPanels() {
			var provider = $providerRadios.filter(':checked').val();
			var enabled = enabledProviders();

			$cards.each(function () {
				var $card = $(this);
				$card.toggleClass('is-selected', $card.find('input[type="radio"]').val() === provider);
			});

			$('.blt-provider-panel').each(function () {
				var $panel = $(this);
				$panel.toggle(enabled.indexOf(String($panel.data('provider'))) !== -1);
			});

			// A provider cannot be the default while it is switched off, so
			// ticking it off also releases the radio next to it.
			$enableBoxes.each(function () {
				var $box = $(this);
				var slug = $box.val();

				if (!$box.prop('checked') && slug === provider) {
					$providerRadios.filter('[value="none"]').prop('checked', true);
				}
			});
		}

		if ($cards.length) {
			$providerRadios.on('change', syncProviderPanels);
			$enableBoxes.on('change', syncProviderPanels);
			syncProviderPanels();
		}

		// --- Copy shortcode buttons ---
		$(document).on('click', '.blt-copy-shortcode', function () {
			var $button = $(this);
			var text = $button.data('shortcode') || '';

			function markCopied() {
				var original = $button.text();
				$button.addClass('is-copied').text($button.data('copiedLabel') || 'Copied!');
				window.setTimeout(function () {
					$button.removeClass('is-copied').text(original);
				}, 1600);
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(markCopied);
				return;
			}

			// Fallback for non-secure contexts.
			var $temp = $('<textarea readonly>').val(text).css({ position: 'absolute', left: '-9999px' }).appendTo('body');
			$temp[0].select();
			try {
				document.execCommand('copy');
				markCopied();
			} catch (e) {
				// Leave the button as-is; user can select the code manually.
			}
			$temp.remove();
		});
	});
})(jQuery);
