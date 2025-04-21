(function ($) {
	"use strict";

	// Import Stripe.js
	let stripe = null;
	if (typeof Stripe === "function") {
		stripe = Stripe(obieEventPaymentData.stripeKey);
	} else {
		console.error("Stripe.js is not loaded.");
		return; // Exit early if Stripe.js is not loaded
	}

	let elements = stripe.elements();
	let card = null;
	let totalPrice = 0;
	let couponApplied = null;
	let cardDestroyed = true; // Add flag to track if card has been properly destroyed

	// Function to cleanup card element
	const destroyCard = () => {
		if (!card) return;

		card.destroy(); // Use destroy() instead of unmount()
		card = null;
		cardDestroyed = true;

		const errorElement = $("#card-errors");
		if (errorElement) errorElement.text("");
	};

	// Function to create and mount card element
	const createCard = () => {
		// Only create new card if previous one was destroyed
		if (!cardDestroyed) return;

		card = elements.create("card");
		card.mount("#card-element");
		card.addEventListener("change", function (event) {
			let displayError = $("#card-errors");
			if (displayError) displayError.text(event.error ? event.error.message : "");
		});
		cardDestroyed = false;
	};

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
		if (value > 0) input.val(value - 1).trigger("change");
	});

	// Calculate the total when quantities change
	$(".ticket-quantity").on("change", function () {
		let total = 0;
		let totalDiscount = 0;

		$(".ticket-quantity").each(function () {
			const quantity = Number.parseInt($(this).val(), 10);
			const price = Number.parseFloat($(this).data("price"));
			if (!isNaN(quantity) && !isNaN(price)) {
				total += quantity * price;
			}
		});

		totalPrice = total;

		if (couponApplied) {
			totalDiscount = couponApplied.discount_type == "percentage" ? (totalPrice * couponApplied.amount) / 100 : couponApplied.amount;
			$(".coupon-discount-amount").text(formatPrice(totalDiscount));
		}

		$(".total-amount").text(formatPrice(totalPrice - totalDiscount, true));

		if (totalPrice > 0) {
			createCard();
			$("#obie-events-reserve-button").text("Purchase tickets");
		} else {
			destroyCard();
			$("#obie-events-reserve-button").text("Reserve now");
		}
	});

	const applyCoupon = (coupon) => {
		$("#coupon-form").hide();
		$("#coupon-discount").show();

		$('input[name="coupon_code"]').val("");
		$('input[name="applied_coupon"]').val(coupon.coupon_code);

		$("#coupon-message").text("Coupon applied");

		couponApplied = coupon;

		const discount_amount = coupon.discount_type == "percentage" ? (totalPrice * coupon.amount) / 100 : coupon.amount;
		$(".coupon-discount-amount").text(formatPrice(discount_amount));

		$(".total-amount").text(formatPrice(totalPrice - discount_amount, true));
	};

	const removeCoupon = () => {
		$("#coupon-form").show();
		$("#coupon-discount").hide();

		$('input[name="coupon_code"]').val("");
		$('input[name="applied_coupon"]').val("");

		$("#coupon-message").text("");

		couponApplied = null;

		$(".coupon-discount-amount").text("");

		$(".total-amount").text(formatPrice(totalPrice, true));
	};

	//
	$("#obie-events-apply-coupon").on("click", async function () {
		const code = $("#coupon_code").val();
		if (!code) {
			$("#coupon-message").text("Please enter a coupon code.");
			return;
		}

		try {
			const response = await $.ajax({
				url: obieEventPaymentData.ajaxUrl,
				type: "POST",
				data: {
					action: "obie_validate_coupon",
					coupon_code: code,
					event_id: $('input[name="event_id"]').val(),
					oe_coupon_nonce: $('input[name="oe_coupon_nonce"]').val(),
				},
			});

			if (!response.success) throw response?.data;
			applyCoupon(response.data.coupon);
		} catch (error) {
			const errorElement = $("#coupon-message");
			if (errorElement) {
				errorElement.text(error.message);
			}
			console.error("Coupon error:", error);
		}
	});

	//
	$("#obie-events-remove-coupon").on("click", function () {
		removeCoupon();
	});

	// Manage form submission
	let form = $("#obie-events-registration-form");
	if (form) {
		form.on("submit", async function (event) {
			event.preventDefault();
			let tickets = [];
			let totalTickets = 0;

			$(".ticket-quantity").each(function () {
				const quantity = Number.parseInt($(this).val(), 10);
				if (!isNaN(quantity) && quantity > 0) {
					totalTickets += quantity;
					tickets.push({
						index: $(this).data("index"),
						quantity: quantity,
					});
				}
			});

			let ticketSelection = $(".ticket-selection");
			if (ticketSelection.length !== 0 && totalTickets <= 0) {
				alert("Please select at least one ticket");
				return;
			}

			try {
				const response = await $.ajax({
					url: obieEventPaymentData.ajaxUrl,
					type: "POST",
					data: {
						action: totalPrice > 0 ? "obie_create_payment_intent" : "obie_event_registration",
						event_id: $('input[name="event_id"]').val(),
						customer_name: $('input[name="customer_name"]').val(),
						customer_email: $('input[name="customer_email"]').val(),
						tickets: JSON.stringify(tickets),
						coupon_code: couponApplied ? couponApplied.coupon_code : null,
						oe_registration_nonce: $('input[name="oe_registration_nonce"]').val(),
					},
				});

				if (!response.success) throw new Error(response.data.error);

				if (totalPrice > 0) {
					// Confirm Payment
					const result = await stripe.confirmCardPayment(response.data.clientSecret, {
						payment_method: {
							card: card,
							billing_details: {
								name: $('input[name="customer_name"]').val(),
								email: $('input[name="customer_email"]').val(),
							},
						},
					});

					if (result.error) throw new Error(result.error.message);
				}

				// Reserved
				alert("Your tickets have been reserved.");
				window.location.reload();
			} catch (error) {
				const errorElement = $("#card-errors");
				if (errorElement) {
					errorElement.text(error.message);
				}
				console.error("Payment error:", error);
			}
		});
	}
})(jQuery);
