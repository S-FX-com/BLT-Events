/**
 * ZymEvents - Registration Form JavaScript
 *
 * Handles ticket quantity changes, total calculation, coupon application,
 * form validation, and submission (free events via AJAX).
 */
(function ($) {
	"use strict";

	var totalPrice = 0;
	var appliedCoupon = null;

	// --- Ticket quantity change ---
	$(document).on("change", ".zymevents-registration-form .zymevents-ticket-quantity", function () {
		recalculateTotal();
	});

	function recalculateTotal() {
		var total = 0;
		var hasTickets = false;

		$(".zymevents-registration-form .zymevents-ticket-quantity").each(function () {
			var qty = parseInt($(this).val(), 10) || 0;
			var price = parseFloat($(this).data("price")) || 0;
			if (qty > 0) {
				total += qty * price;
				hasTickets = true;
			}
		});

		totalPrice = total;

		// Update total display
		$(".zymevents-registration-form .zymevents-total-amount").text(
			formatPrice(totalPrice, true)
		);

		// Update submit button
		var btn = $("#zymevents-submit-btn");
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
			$("#zymevents-payment-section").show();
		} else {
			$("#zymevents-payment-section").hide();
		}
	}

	// --- Coupon application ---
	$(document).on("click", "#zymevents-apply-coupon", function () {
		var code = $("#coupon_code").val().trim();
		if (!code) return;

		var data = window.zymRegData || {};
		var totalQty = 0;
		$(".zymevents-ticket-quantity").each(function () {
			totalQty += parseInt($(this).val(), 10) || 0;
		});

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "zymevents_validate_coupon",
				nonce: data.nonce,
				coupon_code: code,
				event_id: data.eventId,
				quantity: totalQty,
			},
			success: function (response) {
				var msgEl = $("#zymevents-coupon-message");
				if (response.success) {
					appliedCoupon = response.data;
					msgEl
						.text("Coupon applied: " + response.data.label)
						.removeClass("zymevents-msg-error")
						.addClass("zymevents-msg-success")
						.show();
				} else {
					appliedCoupon = null;
					msgEl
						.text(response.data.message)
						.removeClass("zymevents-msg-success")
						.addClass("zymevents-msg-error")
						.show();
				}
			},
		});
	});

	// --- Form submission ---
	$(document).on("submit", "#zymevents-registration-form", function (e) {
		e.preventDefault();

		var form = $(this);
		var data = window.zymRegData || {};

		// For paid events with Stripe, payment.js handles submission
		if (totalPrice > 0 && data.provider === "stripe") {
			return; // payment.js takes over
		}

		// Free registration via AJAX
		var btn = $("#zymevents-submit-btn");
		btn.prop("disabled", true).text("Registering...");

		var formData = form.serializeArray();
		formData.push({ name: "action", value: "cmt_register" });
		formData.push({ name: "nonce", value: data.nonce });

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: $.param(formData),
			success: function (response) {
				var msgEl = $("#zymevents-form-messages");
				if (response.success) {
					msgEl
						.text(response.data.message)
						.removeClass("zymevents-msg-error")
						.addClass("zymevents-msg-success")
						.show();
					form.find("fieldset, input, select, textarea, button").prop(
						"disabled",
						true
					);
					btn.text("Registration Complete");
				} else {
					msgEl
						.text(response.data.message)
						.removeClass("zymevents-msg-success")
						.addClass("zymevents-msg-error")
						.show();
					btn.prop("disabled", false).text("Register — Free");
				}
			},
			error: function () {
				$("#zymevents-form-messages")
					.text("An error occurred. Please try again.")
					.addClass("zymevents-msg-error")
					.show();
				btn.prop("disabled", false).text("Register — Free");
			},
		});
	});
})(jQuery);
