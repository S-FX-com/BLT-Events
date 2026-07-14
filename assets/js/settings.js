/**
 * BLT Events - Settings screen behaviour.
 *
 * - Payments tab: highlights the selected provider card and shows only
 *   that provider's settings panel.
 * - Shortcodes tab: copy-to-clipboard buttons.
 */
(function ($) {
	'use strict';

	$(function () {
		// --- Payment provider selection cards ---
		var $providerRadios = $('input[name="blt_events_payment_provider"]');
		var $cards = $providerRadios.closest('.blt-select-card');

		function syncProviderPanels() {
			var provider = $providerRadios.filter(':checked').val();

			$cards.each(function () {
				var $card = $(this);
				$card.toggleClass('is-selected', $card.find('input[type="radio"]').val() === provider);
			});

			$('.blt-provider-panel').each(function () {
				var $panel = $(this);
				$panel.toggle($panel.data('provider') === provider);
			});
		}

		if ($cards.length) {
			$providerRadios.on('change', syncProviderPanels);
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
