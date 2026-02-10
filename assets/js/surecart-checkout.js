/**
 * Obie Events — SureCart Checkout Integration
 *
 * Handles ticket quantity selection and builds SureCart checkout URLs
 * with the appropriate line items (price_id + quantity).
 */
(function ($) {
	"use strict";

	var totalPrice = 0;
	var checkoutUrl = obieEventSurecartData.checkoutUrl || "/checkout";

	// Handle plus button click
	$(document).on("click", ".obie-events-surecart-form .plus-btn", function () {
		var input = $(this).siblings(".sc-ticket-quantity");
		var value = parseInt(input.val(), 10);
		input.val(value + 1).trigger("change");
	});

	// Handle minus button click
	$(document).on("click", ".obie-events-surecart-form .minus-btn", function () {
		var input = $(this).siblings(".sc-ticket-quantity");
		var value = parseInt(input.val(), 10);
		if (value > 0) {
			input.val(value - 1).trigger("change");
		}
	});

	// Calculate total when quantities change and update checkout button
	$(document).on("change", ".sc-ticket-quantity", function () {
		var total = 0;
		var hasTickets = false;

		$(".sc-ticket-quantity").each(function () {
			var quantity = parseInt($(this).val(), 10);
			var price = parseFloat($(this).data("price"));
			if (!isNaN(quantity) && !isNaN(price) && quantity > 0) {
				total += quantity * price;
				hasTickets = true;
			}
		});

		totalPrice = total;

		// Update total display
		$(".obie-events-surecart-form .total-amount").text(
			formatPrice(totalPrice, true)
		);

		// Enable/disable checkout button
		var checkoutBtn = $("#obie-sc-checkout-btn");
		if (hasTickets) {
			checkoutBtn.prop("disabled", false);
			checkoutBtn.text(
				totalPrice > 0 ? "Proceed to Checkout" : "Reserve Now — Free"
			);
		} else {
			checkoutBtn.prop("disabled", true);
			checkoutBtn.text("Proceed to Checkout");
		}
	});

	// Handle checkout button click — build URL and redirect
	$(document).on("click", "#obie-sc-checkout-btn", function (e) {
		e.preventDefault();

		var lineItems = [];
		var totalTickets = 0;

		$(".sc-ticket-quantity").each(function () {
			var quantity = parseInt($(this).val(), 10);
			var priceId = $(this).data("price-id");

			if (!isNaN(quantity) && quantity > 0 && priceId) {
				lineItems.push({
					price_id: priceId,
					quantity: quantity,
				});
				totalTickets += quantity;
			}
		});

		if (totalTickets <= 0) {
			showMessage("Please select at least one ticket.", "error");
			return;
		}

		// Build SureCart checkout URL with line items
		var url = buildCheckoutUrl(lineItems);

		// Show loading state
		$(this).prop("disabled", true).text("Redirecting to checkout...");
		showMessage("", "");

		// Redirect to SureCart checkout
		window.location.href = url;
	});

	/**
	 * Build a SureCart checkout URL with line items as query parameters.
	 *
	 * Format: /checkout?line_items[0][price_id]=xxx&line_items[0][quantity]=1
	 */
	function buildCheckoutUrl(lineItems) {
		var params = [];

		for (var i = 0; i < lineItems.length; i++) {
			params.push(
				"line_items[" +
					i +
					"][price_id]=" +
					encodeURIComponent(lineItems[i].price_id)
			);
			params.push(
				"line_items[" +
					i +
					"][quantity]=" +
					encodeURIComponent(lineItems[i].quantity)
			);
		}

		var separator = checkoutUrl.indexOf("?") !== -1 ? "&" : "?";
		return checkoutUrl + separator + params.join("&");
	}

	/**
	 * Show a message in the checkout message area.
	 */
	function showMessage(message, type) {
		var el = $("#sc-checkout-message");
		if (!message) {
			el.hide().text("");
			return;
		}
		el.text(message)
			.removeClass("sc-message-error sc-message-success")
			.addClass(type === "error" ? "sc-message-error" : "sc-message-success")
			.show();
	}
})(jQuery);
