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
	let cardDestroyed = true; // Add flag to track if card has been properly destroyed

	// Function to cleanup card element
	const destroyCard = () => {
		if (card) {
			card.destroy(); // Use destroy() instead of unmount()
			card = null;
			cardDestroyed = true;

			const errorElement = document.getElementById("card-errors");
			if (errorElement) {
				errorElement.textContent = "";
			}
		}
	};

	// Function to create and mount card element
	const createCard = () => {
		if (cardDestroyed) {
			// Only create new card if previous one was destroyed
			card = elements.create("card");
			card.mount("#card-element");
			card.addEventListener("change", function (event) {
				let displayError = document.getElementById("card-errors");
				if (displayError) {
					displayError.textContent = event.error ? event.error.message : "";
				}
			});
			cardDestroyed = false;
		}
	};

	// Calculate the total when quantities change
	$(".ticket-quantity").on("change", function () {
		let total = 0;

		$(".ticket-quantity").each(function () {
			const quantity = Number.parseInt($(this).val(), 10);
			const price = Number.parseFloat($(this).data("price"));
			if (!isNaN(quantity) && !isNaN(price)) {
				total += quantity * price;
			}
		});

		totalPrice = total;
		$(".total-amount").text(formatPrice(totalPrice, true));

		if (totalPrice > 0) {
			createCard();
			document.getElementById("obie-events-reserve-button").textContent = "Purchase tickets";
		} else {
			destroyCard();
			document.getElementById("obie-events-reserve-button").textContent = "Reserve now";
		}
	});

	// Manage form submission
	let form = document.getElementById("obie-events-reservation-form");
	if (form) {
		form.addEventListener("submit", async function (event) {
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
						action: totalPrice > 0 ? "obie_create_payment_intent" : "obie_event_reservation",
						event_id: $('input[name="event_id"]').val(),
						customer_name: $('input[name="customer_name"]').val(),
						customer_email: $('input[name="customer_email"]').val(),
						tickets: JSON.stringify(tickets),
						obie_event_reservation_nonce: $('input[name="obie_event_reservation_nonce"]').val(),
					},
				});

				if (!response.success) {
					throw new Error(response.data.error);
				}

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

					if (result.error) {
						throw new Error(result.error.message);
					}
				}

				// Reserved
				alert("Your tickets have been reserved.");
				window.location.reload();
			} catch (error) {
				const errorElement = document.getElementById("card-errors");
				if (errorElement) {
					errorElement.textContent = error.message;
				}
				console.error("Payment error:", error);
			}
		});
	}
})(jQuery);
