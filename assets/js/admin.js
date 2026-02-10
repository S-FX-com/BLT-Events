/**
 * CMT Events - Admin JavaScript
 *
 * Handles admin UI interactions: coupon code generation, payment provider toggle, etc.
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

	// Payment provider toggle on settings page
	$('input[name="cmt_events_payment_provider"]').on("change", function () {
		var provider = $(this).val();
		$(".cmt-provider-stripe, .cmt-provider-surecart").hide();
		if (provider !== "none") {
			$(".cmt-provider-" + provider).show();
		}
	});
});
