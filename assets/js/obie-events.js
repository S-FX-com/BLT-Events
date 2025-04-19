function formatPrice(price, includeTotal = false) {
	// Format number to 2 decimal places
	const formattedPrice = parseFloat(price).toFixed(2);
	let priceString = "";

	// Add "Total:" if requested
	if (includeTotal) {
		priceString += "Total: ";
	}

	// Add currency symbol if enabled
	if (obieEventData.showSymbol == "1") {
		priceString += obieEventData.currencySymbol[obieEventData.currency] || "";
	}

	// Add the price
	priceString += formattedPrice;

	// Add currency code if enabled
	if (obieEventData.showCurrency == "1") {
		priceString += " " + obieEventData.currency;
	}

	return priceString;
}

jQuery(document).ready(function ($) {
	// Handle plus button click
	$(".plus-btn").on("click", function () {
		var input = $(this).siblings(".ticket-quantity");
		var value = parseInt(input.val());
		input.val(value + 1).trigger("change");
	});

	// Handle minus button click
	$(".minus-btn").on("click", function () {
		var input = $(this).siblings(".ticket-quantity");
		var value = parseInt(input.val());
		if (value > 0) {
			input.val(value - 1).trigger("change");
		}
	});

	// Update total when quantity changes
	$(".ticket-quantity").on("change", function () {
		var total = 0;
		$(".ticket-quantity").each(function () {
			var quantity = parseInt($(this).val());
			var price = parseFloat($(this).data("price"));
			total += quantity * price;
		});

		$(".total-amount").text(formatPrice(total));
	});
});
