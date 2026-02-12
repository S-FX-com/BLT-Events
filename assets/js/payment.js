/**
 * CMT Events - Stripe Payment Integration
 *
 * Handles Stripe card element, payment intent creation,
 * and payment confirmation flow.
 */
(function ($) {
	"use strict";

	var stripe, cardElement;

	$(document).ready(function () {
		var data = window.cmtStripeData;
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

		var cardEl = document.getElementById("cmt-card-element");
		if (cardEl) {
			cardElement.mount("#cmt-card-element");
		}

		cardElement.on("change", function (event) {
			var errorsEl = document.getElementById("cmt-card-errors");
			if (errorsEl) {
				errorsEl.textContent = event.error ? event.error.message : "";
			}
		});
	});

	// Intercept form submission for Stripe payments
	$(document).on("submit", "#cmt-registration-form", function (e) {
		var data = window.cmtRegData || {};

		// Only handle Stripe paid events
		if (data.provider !== "stripe") return;

		var totalEl = $(".cmt-registration-form .cmt-total-amount");
		var totalText = totalEl.text();
		var amount = parseFloat(totalText.replace(/[^0-9.]/g, ""));
		if (!amount || amount <= 0) return; // Free event — let registration-form.js handle

		e.preventDefault();

		var btn = $("#cmt-submit-btn");
		btn.prop("disabled", true).text("Processing payment...");

		// Step 1: Create Payment Intent
		$.ajax({
			url: cmtStripeData.ajaxUrl,
			method: "POST",
			data: {
				action: "cmt_create_payment_intent",
				nonce: cmtStripeData.nonce,
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
		var formData = $("#cmt-registration-form").serializeArray();
		formData.push({
			name: "action",
			value: "cmt_confirm_stripe_payment",
		});
		formData.push({ name: "nonce", value: cmtStripeData.nonce });
		formData.push({ name: "payment_intent_id", value: paymentIntentId });

		$.ajax({
			url: cmtStripeData.ajaxUrl,
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
					$(
						"#cmt-registration-form fieldset, #cmt-registration-form input, #cmt-registration-form select, #cmt-registration-form textarea, #cmt-registration-form button"
					).prop("disabled", true);
					$("#cmt-submit-btn").text("Registration Complete");
				} else {
					showError(response.data.message);
					$("#cmt-submit-btn")
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
		$("#cmt-form-messages")
			.text(message)
			.removeClass("cmt-msg-success")
			.addClass("cmt-msg-error")
			.show();
	}
})(jQuery);
