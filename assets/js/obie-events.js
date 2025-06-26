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
	// Cambiar vista del calendario
	if ($(".obie-events-calendar-switch").length) {
		$(".obie-events-calendar-switch button").on("click", function () {
			var view = $(this).data("view");
			var $calendar = $(this).closest(".obie-events-calendar-wrapper");
			$calendar.find(".obie-events-calendar-view").hide();
			$calendar.find('.obie-events-calendar-view[data-view="' + view + '"]').show();
			$calendar.find(".obie-events-calendar-switch button").removeClass("active");
			$(this).addClass("active");
		});
	}
});
