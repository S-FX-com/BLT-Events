/**
 * ZymEvents - Stripe Payment Integration
 *
 * Handles Stripe card element, payment intent creation,
 * and payment confirmation flow.
 */
(function ($) {
	"use strict";

	var stripe, cardElement;

	$(document).ready(function () {
		var data = window.zymStripeData;
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

		var cardEl = document.getElementById("zymevents-card-element");
		if (cardEl) {
			cardElement.mount("#zymevents-card-element");
		}

		cardElement.on("change", function (event) {
			var errorsEl = document.getElementById("zymevents-card-errors");
			if (errorsEl) {
				errorsEl.textContent = event.error ? event.error.message : "";
			}
		});
	});

	// Intercept form submission for Stripe payments
	$(document).on("submit", "#zymevents-registration-form", function (e) {
		var data = window.zymRegData || {};

		// Only handle Stripe paid events
		if (data.provider !== "stripe") return;

		var totalEl = $(".zymevents-registration-form .zymevents-total-amount");
		var totalText = totalEl.text();
		var amount = parseFloat(totalText.replace(/[^0-9.]/g, ""));
		if (!amount || amount <= 0) return; // Free event — let registration-form.js handle

		e.preventDefault();

		var btn = $("#zymevents-submit-btn");
		btn.prop("disabled", true).text("Processing payment...");

		// Step 1: Create Payment Intent
		$.ajax({
			url: zymStripeData.ajaxUrl,
			method: "POST",
			data: {
				action: "zymevents_create_payment_intent",
				nonce: zymStripeData.nonce,
				event_id: data.eventId,
				amount: amount,
			},
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
		var formData = $("#zymevents-registration-form").serializeArray();
		formData.push({
			name: "action",
			value: "zymevents_confirm_stripe_payment",
		});
		formData.push({ name: "nonce", value: zymStripeData.nonce });
		formData.push({ name: "payment_intent_id", value: paymentIntentId });

		$.ajax({
			url: zymStripeData.ajaxUrl,
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
					$(
						"#zymevents-registration-form fieldset, #zymevents-registration-form input, #zymevents-registration-form select, #zymevents-registration-form textarea, #zymevents-registration-form button"
					).prop("disabled", true);
					$("#zymevents-submit-btn").text("Registration Complete");
				} else {
					showError(response.data.message);
					$("#zymevents-submit-btn")
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
		$("#zymevents-form-messages")
			.text(message)
			.removeClass("zymevents-msg-success")
			.addClass("zymevents-msg-error")
			.show();
	}
})(jQuery);
