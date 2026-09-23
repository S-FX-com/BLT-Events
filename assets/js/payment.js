/**
 * BLT Events - Stripe Payment Integration
 *
 * Mounts Stripe's card fields (number, expiry and CVC as separate fields,
 * or the single combined card field used by pre-2.5 template overrides),
 * creates the payment intent and confirms the payment. Strings come from
 * bltStripeData.i18n.
 *
 * The registration steps script keeps this handler from running until the
 * visitor submits the final (Review & payment) step.
 */
(function ($) {
	"use strict";

	var stripe;
	var cardElement; // The element handed to confirmCardPayment().
	var complete = {};
	var errors = {};
	var stripeData = window.bltStripeData || {};
	var i18n = stripeData.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function registration() {
		return (window.bltEvents && window.bltEvents.registration) || {};
	}

	function setLabel(text) {
		var api = registration();
		if (typeof api.setSubmitLabel === "function") {
			api.setSubmitLabel(text);
		} else {
			$("#blt-submit-btn").text(text);
		}
	}

	function showError(message) {
		$("#blt-form-messages")
			.text(message)
			.removeClass("blt-msg-success")
			.addClass("blt-msg-error")
			.prop("hidden", false);
	}

	function resetButton() {
		$("#blt-submit-btn").prop("disabled", false);
		var api = registration();
		if (typeof api.restoreSubmitLabel === "function") {
			api.restoreSubmitLabel();
		} else {
			setLabel(t("registerPay", "Register & Pay"));
		}
	}

	function renderErrors() {
		var el = document.getElementById("blt-card-errors");
		if (!el) {
			return;
		}
		var first = Object.keys(errors).filter(function (k) {
			return errors[k];
		})[0];
		el.textContent = first ? errors[first] : "";
	}

	function track(element, key) {
		complete[key] = false;
		element.on("change", function (event) {
			complete[key] = !!event.complete;
			errors[key] = event.error ? event.error.message : "";
			renderErrors();
		});
	}

	function cardReady() {
		return Object.keys(complete).length > 0 && Object.keys(complete).every(function (k) {
			return complete[k];
		});
	}

	$(function () {
		if (!stripeData.publishableKey || typeof window.Stripe !== "function") {
			return;
		}

		var numberEl = document.getElementById("blt-card-number");
		var legacyEl = document.getElementById("blt-card-element");
		if (!numberEl && !legacyEl) {
			return;
		}

		stripe = window.Stripe(stripeData.publishableKey);
		var elements = stripe.elements();
		var style = {
			base: {
				fontSize: "15px",
				color: "#1f2937",
				"::placeholder": { color: "#9ca3af" }
			}
		};

		if (numberEl) {
			cardElement = elements.create("cardNumber", { style: style, showIcon: true });
			cardElement.mount(numberEl);
			track(cardElement, "number");

			var expiry = elements.create("cardExpiry", { style: style });
			expiry.mount("#blt-card-expiry");
			track(expiry, "expiry");

			var cvc = elements.create("cardCvc", { style: style });
			cvc.mount("#blt-card-cvc");
			track(cvc, "cvc");
		} else {
			cardElement = elements.create("card", { style: style });
			cardElement.mount(legacyEl);
			track(cardElement, "card");
		}
	});

	function orderTotal(form) {
		var fromData = parseFloat($(form).attr("data-total"));
		if (!isNaN(fromData)) {
			return fromData;
		}
		// Pre-2.5 template overrides only carry the formatted total.
		var totalText = $(".blt-registration-form .blt-total-amount").text();
		return parseFloat(totalText.replace(/[^0-9.]/g, "")) || 0;
	}

	// Intercept form submission for Stripe payments.
	$(document).on("submit", "#blt-registration-form", function (e) {
		var regData = window.bltRegData || {};

		if (regData.provider !== "stripe" || !stripe || !cardElement) {
			return;
		}

		// Free selection: registration-form.js handles it.
		if (orderTotal(this) <= 0) {
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		var form = this;
		if (form.reportValidity && !form.reportValidity()) {
			return;
		}

		if (!cardReady()) {
			showError(t("cardIncomplete", "Please enter your card details."));
			return;
		}

		var $btn = $("#blt-submit-btn");
		$btn.prop("disabled", true);
		setLabel(t("processing", "Processing payment…"));
		$("#blt-form-messages").prop("hidden", true);

		var holder = $.trim($(form).find("[data-blt-cardholder]").val() || "");

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

				var method = { card: cardElement };
				if (holder) {
					method.billing_details = { name: holder };
				}

				// Step 2: Confirm the card payment.
				stripe
					.confirmCardPayment(response.data.clientSecret, { payment_method: method })
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
					setLabel(t("complete", "Registration Complete"));
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
