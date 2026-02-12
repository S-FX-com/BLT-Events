/**
 * CMT Events — SureCart Checkout Integration
 *
 * Handles ticket quantity selection and builds SureCart checkout URLs
 * with the appropriate line items (price_id + quantity).
 */
(function ($) {
	"use strict";

	var totalPrice = 0;
	var checkoutUrl = (window.cmtSurecartData && cmtSurecartData.checkoutUrl) || "/checkout";

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
		$(".cmt-surecart-form .cmt-total-amount, .obie-events-surecart-form .total-amount").text(
			formatPrice(totalPrice, true)
		);

		// Enable/disable checkout button
		var checkoutBtn = $("#cmt-sc-checkout-btn, #obie-sc-checkout-btn");
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
	$(document).on("click", "#cmt-sc-checkout-btn, #obie-sc-checkout-btn", function (e) {
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
	 */
	function buildCheckoutUrl(lineItems) {
		var params = [];

		for (var i = 0; i < lineItems.length; i++) {
			params.push(
				"line_items[" + i + "][price_id]=" +
				encodeURIComponent(lineItems[i].price_id)
			);
			params.push(
				"line_items[" + i + "][quantity]=" +
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
		var el = $("#cmt-sc-message, #sc-checkout-message");
		if (!message) {
			el.hide().text("");
			return;
		}
		el.text(message)
			.removeClass("cmt-msg-error cmt-msg-success sc-message-error sc-message-success")
			.addClass(type === "error" ? "cmt-msg-error" : "cmt-msg-success")
			.show();
	}
})(jQuery);
