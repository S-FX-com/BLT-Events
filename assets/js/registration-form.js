/**
 * BLT Events - Registration Form JavaScript
 *
 * Handles ticket quantity changes, total calculation, coupon application,
 * form validation, and submission (free events via AJAX).
 */
(function ($) {
	"use strict";

	var totalPrice = 0;
	var appliedCoupon = null;

	// --- Ticket quantity change ---
	$(document).on("change", ".blt-registration-form .blt-ticket-quantity", function () {
		recalculateTotal();
	});

	function recalculateTotal() {
		var total = 0;
		var hasTickets = false;

		$(".blt-registration-form .blt-ticket-quantity").each(function () {
			var qty = parseInt($(this).val(), 10) || 0;
			var price = parseFloat($(this).data("price")) || 0;
			if (qty > 0) {
				total += qty * price;
				hasTickets = true;
			}
		});

		// Apply coupon discount to the displayed total (the server
		// recomputes the authoritative amount at checkout).
		if (appliedCoupon) {
			var amount = parseFloat(appliedCoupon.amount) || 0;
			var discount =
				appliedCoupon.type === "percentage"
					? total * (amount / 100)
					: amount;
			total = Math.max(0, total - discount);
		}

		totalPrice = Math.round(total * 100) / 100;

		// Update total display
		$(".blt-registration-form .blt-total-amount").text(
			formatPrice(totalPrice, true)
		);

		// Update submit button
		var btn = $("#blt-submit-btn");
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
			$("#blt-payment-section").show();
		} else {
			$("#blt-payment-section").hide();
		}
	}

	// --- Coupon application ---
	$(document).on("click", "#blt-apply-coupon", function () {
		var code = $("#coupon_code").val().trim();
		if (!code) return;

		var data = window.bltRegData || {};
		var totalQty = 0;
		$(".blt-ticket-quantity").each(function () {
			totalQty += parseInt($(this).val(), 10) || 0;
		});

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "blt_validate_coupon",
				nonce: data.nonce,
				coupon_code: code,
				event_id: data.eventId,
				quantity: totalQty,
			},
			success: function (response) {
				var msgEl = $("#blt-coupon-message");
				if (response.success) {
					appliedCoupon = response.data;
					msgEl
						.text("Coupon applied: " + response.data.label)
						.removeClass("blt-msg-error")
						.addClass("blt-msg-success")
						.show();
				} else {
					appliedCoupon = null;
					msgEl
						.text(response.data.message)
						.removeClass("blt-msg-success")
						.addClass("blt-msg-error")
						.show();
				}
				recalculateTotal();
			},
		});
	});

	// --- Form submission ---
	$(document).on("submit", "#blt-registration-form", function (e) {
		e.preventDefault();

		var form = $(this);
		var data = window.bltRegData || {};

		// For paid events with Stripe, payment.js handles submission
		if (totalPrice > 0 && data.provider === "stripe") {
			return; // payment.js takes over
		}

		// Free registration via AJAX
		var btn = $("#blt-submit-btn");
		btn.prop("disabled", true).text("Registering...");

		var formData = form.serializeArray();
		formData.push({ name: "action", value: "blt_register" });
		formData.push({ name: "nonce", value: data.nonce });

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: $.param(formData),
			success: function (response) {
				var msgEl = $("#blt-form-messages");
				if (response.success) {
					msgEl
						.text(response.data.message)
						.removeClass("blt-msg-error")
						.addClass("blt-msg-success")
						.show();
					form.find("fieldset, input, select, textarea, button").prop(
						"disabled",
						true
					);
					btn.text("Registration Complete");
				} else {
					msgEl
						.text(response.data.message)
						.removeClass("blt-msg-success")
						.addClass("blt-msg-error")
						.show();
					btn.prop("disabled", false).text("Register — Free");
				}
			},
			error: function () {
				$("#blt-form-messages")
					.text("An error occurred. Please try again.")
					.addClass("blt-msg-error")
					.show();
				btn.prop("disabled", false).text("Register — Free");
			},
		});
	});
})(jQuery);
