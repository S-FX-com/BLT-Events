/**
 * CMT Events - Core Frontend JavaScript
 *
 * Shared utilities: price formatting, quantity controls, etc.
 */
(function ($) {
	"use strict";

	window.cmtEvents = window.cmtEvents || {};

	/**
	 * Format a price using the plugin's currency settings.
	 */
	cmtEvents.formatPrice = function (amount, includeTotal) {
		var config = (window.cmtEventsData && window.cmtEventsData.currency) || {};
		var formatted = parseFloat(amount || 0).toFixed(2);
		var result = "";

		if (includeTotal) {
			result += "Total: ";
		}

		if (config.showSymbol === "1" && config.currencySymbol) {
			result += config.currencySymbol;
		}

		result += formatted;

		if (config.showCurrency === "1" && config.currency) {
			result += " " + config.currency;
		}

		return result;
	};

	// Global helper for formatPrice (used by surecart-checkout.js and others)
	window.formatPrice = cmtEvents.formatPrice;

	/**
	 * Generic quantity +/- button handlers.
	 */
	$(document).on("click", ".cmt-qty-btn.plus-btn", function () {
		var input = $(this).siblings("input[type='number']");
		var val = parseInt(input.val(), 10) || 0;
		input.val(val + 1).trigger("change");
	});

	$(document).on("click", ".cmt-qty-btn.minus-btn", function () {
		var input = $(this).siblings("input[type='number']");
		var val = parseInt(input.val(), 10) || 0;
		if (val > 0) {
			input.val(val - 1).trigger("change");
		}
	});

	/**
	 * Handle "Other" select fields.
	 */
	$(document).on("change", ".cmt-field-wrap select", function () {
		var otherInput = $(this).siblings(".cmt-other-input");
		if ($(this).val() === "__other__") {
			otherInput.show().focus();
		} else {
			otherInput.hide();
		}
	});
})(jQuery);
