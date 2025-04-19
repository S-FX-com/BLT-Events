jQuery(document).ready(function ($) {
	if ($("#generate_coupon_code")) {
		// Generate random coupon code
		$("#generate_coupon_code").on("click", function () {
			var code = Math.random().toString(36).substring(2, 10).toUpperCase();
			$("#coupon_code").val(code);
		});

		// Update amount symbol based on discount type
		$("#discount_type").on("change", function () {
			if ($(this).val() === "percentage") {
				$("#amount_symbol").text("%");
			} else {
				$("#amount_symbol").text("$");
			}
		});
	}
});
