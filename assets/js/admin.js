/**
 * BLT Events - Admin JavaScript
 *
 * Handles general admin UI interactions: coupon code generation and the
 * discount amount symbol. Event editor interactions live in
 * event-editor.js, and settings-page interactions live in settings.js,
 * each loaded only on their own screen.
 */
jQuery(document).ready(function ($) {
	"use strict";

	// Generate random coupon code
	$(document).on("click", "#generate_coupon_code", function () {
		var code = Math.random().toString(36).substring(2, 10).toUpperCase();
		$("#coupon_code").val(code);
	});

	// Update amount symbol based on discount type
	$(document).on("change", "#discount_type", function () {
		var $symbol = $("#amount_symbol");
		if ($(this).val() === "percentage") {
			$symbol.text("%");
		} else {
			$symbol.text($symbol.data("currencySymbol") || "$");
		}
	});

	// ---- Coupon editor: restriction panels + event search ----
	var $couponBox = $(".blt-coupon-details");
	if ($couponBox.length) {
		$couponBox.on("change", 'input[name="restrict_events"]', function () {
			$couponBox.find(".blt-coupon-events-panel").toggle(this.checked);
		});
		$couponBox.on("change", 'input[name="restrict_roles"]', function () {
			$couponBox.find(".blt-coupon-roles-panel").toggle(this.checked);
		});

		// Highlight selected role chips.
		$couponBox.on("change", ".blt-role-choice input", function () {
			$(this).closest(".blt-role-choice").toggleClass("is-selected", this.checked);
		});

		// AJAX event search with debounce.
		var $search = $("#blt-coupon-event-search");
		var $suggestions = $("#blt-coupon-event-suggestions");
		var $chips = $("#blt-coupon-event-chips");
		var searchTimer = null;

		function chipExists(id) {
			return $chips.find('.blt-coupon-event-chip[data-id="' + id + '"]').length > 0;
		}

		function addChip(event) {
			if (chipExists(event.id)) {
				return;
			}
			var $chip = $('<span class="blt-coupon-event-chip"></span>').attr("data-id", event.id);
			$chip.append($('<input type="hidden" name="applicable_events[]">').val(event.id));
			$chip.append(document.createTextNode(event.title + " "));
			if (event.date) {
				$chip.append($("<em></em>").text(event.date));
			}
			$chip.append('<button type="button" class="blt-chip-remove" aria-label="Remove">&times;</button>');
			$chips.append($chip);
		}

		function renderSuggestions(events) {
			$suggestions.empty();
			if (!events.length) {
				$suggestions.hide();
				return;
			}
			events.forEach(function (event) {
				if (chipExists(event.id)) {
					return;
				}
				var $item = $('<li class="blt-address-suggestion"></li>')
					.text(event.title + (event.date ? " — " + event.date : ""))
					.data("event", event);
				$suggestions.append($item);
			});
			$suggestions.toggle($suggestions.children().length > 0);
		}

		$search.on("input", function () {
			var term = $(this).val();
			window.clearTimeout(searchTimer);

			if (term.length < 2) {
				$suggestions.hide().empty();
				return;
			}

			searchTimer = window.setTimeout(function () {
				$.post(ajaxurl, {
					action: "blt_search_events",
					nonce: $couponBox.data("searchNonce"),
					term: term
				}).done(function (response) {
					if (response && response.success) {
						renderSuggestions(response.data.events || []);
					}
				});
			}, 300);
		});

		$suggestions.on("click", ".blt-address-suggestion", function () {
			addChip($(this).data("event"));
			$suggestions.hide().empty();
			$search.val("").trigger("focus");
		});

		$chips.on("click", ".blt-chip-remove", function () {
			$(this).closest(".blt-coupon-event-chip").remove();
		});

		$(document).on("mousedown", function (e) {
			if (!$(e.target).closest(".blt-address-autocomplete").length) {
				$suggestions.hide();
			}
		});
	}
});
