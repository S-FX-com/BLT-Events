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
