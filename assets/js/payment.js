/**
 * BLT Events - Stripe Payment Integration
 *
 * Handles Stripe card element, payment intent creation,
 * and payment confirmation flow.
 */
(function ($) {
	"use strict";

	var stripe, cardElement;

	$(document).ready(function () {
		var data = window.bltStripeData;
		if (!data || !data.publishableKey) return;

		stripe = Stripe(data.publishableKey);
		var elements = stripe.elements();

		cardElement = elements.create("card", {
			style: {
				base: {
					fontSize: "16px",
					color: "#374151",
					"::placeholder": { color: "#9ca3af" },
				},
			},
		});

		var cardEl = document.getElementById("blt-card-element");
		if (cardEl) {
			cardElement.mount("#blt-card-element");
		}

		cardElement.on("change", function (event) {
			var errorsEl = document.getElementById("blt-card-errors");
			if (errorsEl) {
				errorsEl.textContent = event.error ? event.error.message : "";
			}
		});
	});

	// Intercept form submission for Stripe payments
	$(document).on("submit", "#blt-registration-form", function (e) {
		var data = window.bltRegData || {};

		// Only handle Stripe paid events
		if (data.provider !== "stripe") return;

		var totalEl = $(".blt-registration-form .blt-total-amount");
		var totalText = totalEl.text();
		var amount = parseFloat(totalText.replace(/[^0-9.]/g, ""));
		if (!amount || amount <= 0) return; // Free event — let registration-form.js handle

		e.preventDefault();

		var btn = $("#blt-submit-btn");
		btn.prop("disabled", true).text("Processing payment...");

		// Step 1: Create Payment Intent. The full form (ticket quantities,
		// coupon code) is sent so the server computes the charge amount;
		// the displayed total is never trusted.
		var intentData = $("#blt-registration-form").serializeArray();
		intentData.push({ name: "action", value: "blt_create_payment_intent" });
		intentData.push({ name: "nonce", value: bltStripeData.nonce });
		intentData.push({ name: "event_id", value: data.eventId });

		$.ajax({
			url: bltStripeData.ajaxUrl,
			method: "POST",
			data: $.param(intentData),
			success: function (response) {
				if (!response.success) {
					showError(response.data.message);
					btn.prop("disabled", false).text("Register & Pay");
					return;
				}

				// Step 2: Confirm card payment
				stripe
					.confirmCardPayment(response.data.clientSecret, {
						payment_method: { card: cardElement },
					})
					.then(function (result) {
						if (result.error) {
							showError(result.error.message);
							btn.prop("disabled", false).text("Register & Pay");
							return;
						}

						if (result.paymentIntent.status === "succeeded") {
							// Step 3: Create registration with confirmed payment
							confirmRegistration(
								result.paymentIntent.id,
								data.eventId
							);
						}
					});
			},
			error: function () {
				showError("Could not create payment. Please try again.");
				btn.prop("disabled", false).text("Register & Pay");
			},
		});
	});

	function confirmRegistration(paymentIntentId, eventId) {
		var formData = $("#blt-registration-form").serializeArray();
		formData.push({
			name: "action",
			value: "blt_confirm_stripe_payment",
		});
		formData.push({ name: "nonce", value: bltStripeData.nonce });
		formData.push({ name: "payment_intent_id", value: paymentIntentId });

		$.ajax({
			url: bltStripeData.ajaxUrl,
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
					$(
						"#blt-registration-form fieldset, #blt-registration-form input, #blt-registration-form select, #blt-registration-form textarea, #blt-registration-form button"
					).prop("disabled", true);
					$("#blt-submit-btn").text("Registration Complete");
				} else {
					showError(response.data.message);
					$("#blt-submit-btn")
						.prop("disabled", false)
						.text("Register & Pay");
				}
			},
			error: function () {
				showError(
					"Payment succeeded but registration failed. Please contact support."
				);
			},
		});
	}

	function showError(message) {
		$("#blt-form-messages")
			.text(message)
			.removeClass("blt-msg-success")
			.addClass("blt-msg-error")
			.show();
	}
})(jQuery);
