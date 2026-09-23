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
		// --- Warn before leaving a tab with unsaved changes ---
		var $settingsForm = $('.blt-events-settings form[action="options.php"]');

		if ($settingsForm.length) {
			var formDirty = false;

			$settingsForm.on('change input', ':input', function () {
				formDirty = true;
			});

			$(window).on('beforeunload', function (e) {
				if (!formDirty) {
					return;
				}
				// The message string itself is ignored by modern browsers,
				// which always show their own generic wording — only whether
				// a truthy value comes back decides if the prompt appears.
				e.preventDefault();
				e.returnValue = '';
				return '';
			});

			$settingsForm.on('submit', function () {
				formDirty = false;
			});
		}

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

		// --- Appearance: live token preview ---
		var $preview = $('[data-blt-preview]');

		if ($preview.length) {
			var $modeRadios = $('[data-blt-style-mode]');
			var $primary = $('[data-blt-preview-primary]');
			var $radius = $('[data-blt-preview-radius]');

			// Mirrors BLT_Events_Appearance::readable_on() so the preview and
			// the saved page agree on when to flip to dark text.
			function readableOn(hex) {
				var rgb = toRgb(hex);
				if (!rgb) {
					return '#ffffff';
				}

				var channels = rgb.map(function (c) {
					c = c / 255;
					return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
				});

				var luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
				return luminance > 0.45 ? '#111827' : '#ffffff';
			}

			function toRgb(hex) {
				if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex || '')) {
					return null;
				}

				var h = hex.slice(1);
				if (h.length === 3) {
					h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
				}

				return [
					parseInt(h.slice(0, 2), 16),
					parseInt(h.slice(2, 4), 16),
					parseInt(h.slice(4, 6), 16)
				];
			}

			function shade(hex, amount) {
				var rgb = toRgb(hex);
				if (!rgb) {
					return hex;
				}

				return '#' + rgb.map(function (c) {
					var v = amount < 0 ? c * (1 + amount) : c + (255 - c) * amount;
					return ('0' + Math.round(Math.max(0, Math.min(255, v))).toString(16)).slice(-2);
				}).join('');
			}

			function syncPreview() {
				var mode = $modeRadios.filter(':checked').val();

				$modeRadios.closest('.blt-select-card').each(function () {
					var $card = $(this);
					$card.toggleClass('is-selected', $card.find('input[type="radio"]').val() === mode);
				});

				$('[data-blt-style-panel]').toggle(mode !== 'off');

				var primary = ($primary.val() || '').trim();
				var radius = ($radius.val() || '').trim();
				var el = $preview[0];

				if (toRgb(primary)) {
					el.style.setProperty('--blt-e-primary', primary);
					el.style.setProperty('--blt-e-primary-hover', shade(primary, -0.15));
					el.style.setProperty('--blt-e-primary-tint', shade(primary, 0.92));
					el.style.setProperty('--blt-e-on-primary', readableOn(primary));
				} else {
					['--blt-e-primary', '--blt-e-primary-hover', '--blt-e-primary-tint', '--blt-e-on-primary']
						.forEach(function (token) { el.style.removeProperty(token); });
				}

				if (radius !== '' && !isNaN(parseInt(radius, 10))) {
					el.style.setProperty('--blt-e-radius', parseInt(radius, 10) + 'px');
				} else {
					el.style.removeProperty('--blt-e-radius');
				}
			}

			$modeRadios.on('change', syncPreview);
			$primary.on('input change', syncPreview);
			$radius.on('input change', syncPreview);
			syncPreview();
		}

		// --- Shortcode builder ---
		var $builder = $('[data-blt-builder]');

		if ($builder.length) {
			var $output = $builder.find('[data-blt-builder-output]');
			var $copy = $builder.find('[data-blt-builder-copy]');

			function buildShortcode() {
				var parts = [];

				$builder.find('[data-blt-att]').each(function () {
					var $field = $(this);
					var name = $field.data('bltAtt');
					var fallback = String($field.data('bltDefault'));
					var value;

					if ($field.is(':checkbox')) {
						value = $field.prop('checked') ? String($field.data('bltOn')) : fallback;
					} else {
						value = String($field.val() || '');
					}

					// Emit only what differs from the shortcode's own default,
					// so the copied string stays as short as possible.
					if (value === '' || value === fallback) {
						return;
					}

					parts.push(name + '="' + value.replace(/"/g, '') + '"');
				});

				var shortcode = '[blt_events_calendar' + (parts.length ? ' ' + parts.join(' ') : '') + ']';

				$output.text(shortcode);
				$copy.attr('data-shortcode', shortcode).data('shortcode', shortcode);
			}

			$builder.on('change input', '[data-blt-att]', buildShortcode);
			buildShortcode();
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
