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
		if ($(this).val() === "percentage") {
			$("#amount_symbol").text("%");
		} else {
			$("#amount_symbol").text("$");
		}
	});
});
