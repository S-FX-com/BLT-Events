/**
 * CMT Events - Registration Form JavaScript
 *
 * Handles ticket quantity changes, total calculation, coupon application,
 * form validation, and submission (free events via AJAX).
 */
(function ($) {
	"use strict";

	var totalPrice = 0;
	var appliedCoupon = null;

	// --- Ticket quantity change ---
	$(document).on("change", ".cmt-registration-form .cmt-ticket-quantity", function () {
		recalculateTotal();
	});

	function recalculateTotal() {
		var total = 0;
		var hasTickets = false;

		$(".cmt-registration-form .cmt-ticket-quantity").each(function () {
			var qty = parseInt($(this).val(), 10) || 0;
			var price = parseFloat($(this).data("price")) || 0;
			if (qty > 0) {
				total += qty * price;
				hasTickets = true;
			}
		});

		totalPrice = total;

		// Update total display
		$(".cmt-registration-form .cmt-total-amount").text(
			formatPrice(totalPrice, true)
		);

		// Update submit button
		var btn = $("#cmt-submit-btn");
		if (hasTickets) {
			btn.prop("disabled", false);
			btn.text(
				totalPrice > 0 ? "Register & Pay" : "Register — Free"
			);
		} else {
			btn.prop("disabled", true);
			btn.text("Select tickets to continue");
		}

		// Show/hide payment section for paid events
		if (totalPrice > 0) {
			$("#cmt-payment-section").show();
		} else {
			$("#cmt-payment-section").hide();
		}
	}

	// --- Coupon application ---
	$(document).on("click", "#cmt-apply-coupon", function () {
		var code = $("#coupon_code").val().trim();
		if (!code) return;

		var data = window.cmtRegData || {};
		var totalQty = 0;
		$(".cmt-ticket-quantity").each(function () {
			totalQty += parseInt($(this).val(), 10) || 0;
		});

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "cmt_validate_coupon",
				nonce: data.nonce,
				coupon_code: code,
				event_id: data.eventId,
				quantity: totalQty,
			},
			success: function (response) {
				var msgEl = $("#cmt-coupon-message");
				if (response.success) {
					appliedCoupon = response.data;
					msgEl
						.text("Coupon applied: " + response.data.label)
						.removeClass("cmt-msg-error")
						.addClass("cmt-msg-success")
						.show();
				} else {
					appliedCoupon = null;
					msgEl
						.text(response.data.message)
						.removeClass("cmt-msg-success")
						.addClass("cmt-msg-error")
						.show();
				}
			},
		});
	});

	// --- Form submission ---
	$(document).on("submit", "#cmt-registration-form", function (e) {
		e.preventDefault();

		var form = $(this);
		var data = window.cmtRegData || {};

		// For paid events with Stripe, payment.js handles submission
		if (totalPrice > 0 && data.provider === "stripe") {
			return; // payment.js takes over
		}

		// Free registration via AJAX
		var btn = $("#cmt-submit-btn");
		btn.prop("disabled", true).text("Registering...");

		var formData = form.serializeArray();
		formData.push({ name: "action", value: "cmt_register" });
		formData.push({ name: "nonce", value: data.nonce });

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: $.param(formData),
			success: function (response) {
				var msgEl = $("#cmt-form-messages");
				if (response.success) {
					msgEl
						.text(response.data.message)
						.removeClass("cmt-msg-error")
						.addClass("cmt-msg-success")
						.show();
					form.find("fieldset, input, select, textarea, button").prop(
						"disabled",
						true
					);
					btn.text("Registration Complete");
				} else {
					msgEl
						.text(response.data.message)
						.removeClass("cmt-msg-success")
						.addClass("cmt-msg-error")
						.show();
					btn.prop("disabled", false).text("Register — Free");
				}
			},
			error: function () {
				$("#cmt-form-messages")
					.text("An error occurred. Please try again.")
					.addClass("cmt-msg-error")
					.show();
				btn.prop("disabled", false).text("Register — Free");
			},
		});
	});
})(jQuery);
