/**
 * BLT Events — FluentCart Checkout Integration
 *
 * Handles ticket selection and redirects to FluentCart's instant
 * checkout (?fluent-cart=instant_checkout&item_id=X&quantity=N).
 * Instant checkout accepts a single item, so the form uses a
 * ticket-type radio selection plus a quantity field.
 */
(function ($) {
	"use strict";

	function selectedTicket($form) {
		return $form.find('input[name="blt-fc-ticket"]:checked');
	}

	function updateState($form) {
		var $ticket = selectedTicket($form);
		var $btn = $form.find(".blt-fc-checkout-btn");
		var quantity = parseInt($form.find(".blt-fc-quantity").val(), 10);

		if (!$ticket.length || isNaN(quantity) || quantity < 1) {
			$btn.prop("disabled", true);
			$form.find(".blt-total-amount").text(formatPrice(0, true));
			return;
		}

		var price = parseFloat($ticket.data("price")) || 0;
		var total = price * quantity;

		$form.find(".blt-total-amount").text(formatPrice(total, true));
		$btn.prop("disabled", false).text(
			total > 0 ? "Proceed to Checkout" : "Reserve Now — Free"
		);
	}

	$(document).on(
		"change",
		'.blt-fluentcart-form input[name="blt-fc-ticket"], .blt-fluentcart-form .blt-fc-quantity',
		function () {
			updateState($(this).closest(".blt-fluentcart-form"));
		}
	);

	$(document).on("click", ".blt-fc-checkout-btn", function (e) {
		e.preventDefault();

		var $form = $(this).closest(".blt-fluentcart-form");
		var $ticket = selectedTicket($form);
		var quantity = parseInt($form.find(".blt-fc-quantity").val(), 10);

		if (!$ticket.length || isNaN(quantity) || quantity < 1) {
			return;
		}

		var base =
			(window.bltFluentCartData && bltFluentCartData.checkoutBase) || "/";
		var separator = base.indexOf("?") !== -1 ? "&" : "?";
		var url =
			base +
			separator +
			"item_id=" +
			encodeURIComponent($ticket.val()) +
			"&quantity=" +
			encodeURIComponent(quantity);

		$(this).prop("disabled", true).text("Redirecting to checkout...");
		window.location.href = url;
	});

	/**
	 * Format a price using the shared currency config from bltEventsData.
	 */
	function formatPrice(amount, includeTotal) {
		var cfg = (window.bltEventsData && bltEventsData.currency) || {};
		var out = includeTotal ? (cfg.totalLabel || "Total:") + " " : "";

		if (cfg.showSymbol === "1" && cfg.currencySymbol) {
			out += cfg.currencySymbol;
		}

		out += (Math.round(amount * 100) / 100).toFixed(2);

		if (cfg.showCurrency === "1" && cfg.currency) {
			out += " " + cfg.currency;
		}

		return out;
	}
})(jQuery);
