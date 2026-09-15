/**
 * BLT Events - Stripe Payment Integration
 *
 * Handles the Stripe card element, payment intent creation, and the
 * payment confirmation flow. Strings come from bltStripeData.i18n.
 */
(function ($) {
	"use strict";

	var stripe, cardElement, cardComplete = false;
	var stripeData = window.bltStripeData || {};
	var i18n = stripeData.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function showError(message) {
		$("#blt-form-messages")
			.text(message)
			.removeClass("blt-msg-success")
			.addClass("blt-msg-error")
			.prop("hidden", false);
	}

	function resetButton() {
		$("#blt-submit-btn").prop("disabled", false).text(t("registerPay", "Register & Pay"));
	}

	$(function () {
		if (!stripeData.publishableKey || typeof window.Stripe !== "function") {
			return;
		}

		var cardEl = document.getElementById("blt-card-element");
		if (!cardEl) {
			return;
		}

		stripe = window.Stripe(stripeData.publishableKey);
		var elements = stripe.elements();

		cardElement = elements.create("card", {
			style: {
				base: {
					fontSize: "16px",
					color: "#374151",
					"::placeholder": { color: "#9ca3af" }
				}
			}
		});

		cardElement.mount("#blt-card-element");

		cardElement.on("change", function (event) {
			cardComplete = !!event.complete;
			var errorsEl = document.getElementById("blt-card-errors");
			if (errorsEl) {
				errorsEl.textContent = event.error ? event.error.message : "";
			}
		});
	});

	// Intercept form submission for Stripe payments.
	$(document).on("submit", "#blt-registration-form", function (e) {
		var regData = window.bltRegData || {};

		if (regData.provider !== "stripe" || !stripe || !cardElement) {
			return;
		}

		// Free selection: registration-form.js handles it.
		var totalText = $(".blt-registration-form .blt-total-amount").text();
		var amount = parseFloat(totalText.replace(/[^0-9.]/g, ""));
		if (!amount || amount <= 0) {
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		var form = this;
		if (form.reportValidity && !form.reportValidity()) {
			return;
		}

		if (!cardComplete) {
			showError(t("cardIncomplete", "Please enter your card details."));
			return;
		}

		var $btn = $("#blt-submit-btn");
		$btn.prop("disabled", true).text(t("processing", "Processing payment…"));

		// Step 1: Create the Payment Intent. The full form (ticket quantities,
		// coupon code, attendee details) is sent so the server computes the
		// charge amount and can finish the registration from its webhook if
		// this page never gets to step 3.
		var intentData = $(form).serializeArray();
		intentData.push({ name: "action", value: "blt_create_payment_intent" });
		intentData.push({ name: "nonce", value: stripeData.nonce });

		$.ajax({
			url: stripeData.ajaxUrl,
			method: "POST",
			data: $.param(intentData),
			success: function (response) {
				if (!response.success) {
					showError(response.data.message);
					resetButton();
					return;
				}

				// Step 2: Confirm the card payment.
				stripe
					.confirmCardPayment(response.data.clientSecret, {
						payment_method: { card: cardElement }
					})
					.then(function (result) {
						if (result.error) {
							showError(result.error.message);
							resetButton();
							return;
						}

						if (result.paymentIntent && result.paymentIntent.status === "succeeded") {
							// Step 3: Create the registration for the confirmed payment.
							confirmRegistration(form, result.paymentIntent.id);
						}
					});
			},
			error: function () {
				showError(t("createFailed", "Could not create payment. Please try again."));
				resetButton();
			}
		});
	});

	function confirmRegistration(form, paymentIntentId) {
		var formData = $(form).serializeArray();
		formData.push({ name: "action", value: "blt_confirm_stripe_payment" });
		formData.push({ name: "nonce", value: stripeData.nonce });
		formData.push({ name: "payment_intent_id", value: paymentIntentId });

		$.ajax({
			url: stripeData.ajaxUrl,
			method: "POST",
			data: $.param(formData),
			success: function (response) {
				if (response.success) {
					$("#blt-form-messages")
						.text(response.data.message)
						.removeClass("blt-msg-error")
						.addClass("blt-msg-success")
						.prop("hidden", false);
					$(form).find("fieldset, input, select, textarea, button").prop("disabled", true);
					$("#blt-submit-btn").text(t("complete", "Registration Complete"));
					$(form).trigger("blt:registered", [response.data]);
				} else {
					showError(response.data.message);
					resetButton();
				}
			},
			error: function () {
				// The webhook will still create the registration from the
				// stashed form; the visitor just needs to know it went through.
				showError(t("confirmFailed", "Payment succeeded but registration failed. Please contact support and quote your payment reference."));
			}
		});
	}
})(jQuery);
