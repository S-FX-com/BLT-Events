/**
 * BLT Events - Registration Form JavaScript
 *
 * Owns the checkout's state: ticket quantities and totals, coupon and group
 * discounts, per-attendee cards, the review screen, the summary sidebar,
 * conditional fields, and submission of free registrations via AJAX. Paid
 * Stripe registrations are handed over to payment.js; the step flow lives
 * in registration-steps.js.
 *
 * Every visible string comes from bltRegData.i18n (localized in PHP).
 */
(function ($) {
	"use strict";

	var data = window.bltRegData || {};
	var i18n = data.i18n || {};
	var appliedCoupon = null;
	var current = { tickets: [], qty: 0, subtotal: 0, discount: 0, total: 0 };

	// Attendee values carried across a rebuild, keyed by seat. Set by the
	// "Remove attendee" handler so later cards keep what was typed in them.
	var pendingAttendeeValues = null;

	var ns = (window.bltEvents = window.bltEvents || {});
	var api = (ns.registration = ns.registration || {});

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var n = 0;
		return String(str).replace(/%(?:(\d+)\$)?[ds]/g, function (m, pos) {
			var value = pos ? args[parseInt(pos, 10) - 1] : args[n++];
			return value === undefined ? "" : String(value);
		});
	}

	function round2(n) {
		return Math.round((n || 0) * 100) / 100;
	}

	function formatPrice(amount) {
		if (ns && typeof ns.formatPrice === "function") {
			return ns.formatPrice(amount, false);
		}
		return parseFloat(amount || 0).toFixed(2);
	}

	function priceOrFree(amount) {
		return amount > 0 ? formatPrice(amount) : t("free", "Free");
	}

	function ticketCount(n) {
		return sprintf(n === 1 ? t("ticketOne", "%d ticket") : t("ticketMany", "%d tickets"), n);
	}

	function showMessage($el, text, type) {
		if (!$el.length) {
			return;
		}
		$el.text(text)
			.removeClass("blt-msg-error blt-msg-success")
			.addClass(type === "error" ? "blt-msg-error" : "blt-msg-success")
			.prop("hidden", false);
	}

	function $form() {
		return $("#blt-registration-form");
	}

	/* ------------------------------------------------------------------
	 * Submit button label (the steps script knows the right one)
	 * ---------------------------------------------------------------- */

	function setSubmitLabel(text) {
		var $btn = $("#blt-submit-btn");
		var $label = $btn.find("[data-blt-submit-label]");
		($label.length ? $label : $btn).text(text);
	}

	function restoreSubmitLabel() {
		var label = typeof api.submitLabel === "function" ? api.submitLabel() : "";
		setSubmitLabel(label || t("completeFree", "Complete registration"));
	}

	api.setSubmitLabel = setSubmitLabel;
	api.restoreSubmitLabel = restoreSubmitLabel;

	/* ------------------------------------------------------------------
	 * Tickets & totals
	 * ---------------------------------------------------------------- */

	function selectedTickets() {
		var tickets = [];
		$form().find(".blt-ticket-quantity").each(function () {
			var $input = $(this);
			var qty = parseInt($input.val(), 10) || 0;
			if (qty > 0) {
				tickets.push({
					index: String($input.data("index")),
					name: String($input.data("name") || ""),
					description: String($input.data("description") || ""),
					price: parseFloat($input.data("price")) || 0,
					qty: qty
				});
			}
		});
		return tickets;
	}

	/**
	 * Same arithmetic as the server: group discount on the subtotal, then
	 * the coupon on what is left. The server recomputes the amount charged.
	 */
	function computePricing() {
		var tickets = selectedTickets();
		var subtotal = 0;
		var qty = 0;
		var discount = 0;

		tickets.forEach(function (ticket) {
			subtotal += ticket.qty * ticket.price;
			qty += ticket.qty;
		});

		var group = data.groupDiscount;
		if (group && group.enabled && qty >= (parseInt(group.min_attendees, 10) || 0)) {
			var groupAmount = parseFloat(group.amount) || 0;
			var groupDiscount = 0;
			if (group.type === "percentage") {
				groupDiscount = subtotal * (groupAmount / 100);
			} else if (group.type === "flat") {
				groupDiscount = groupAmount;
			}
			discount += Math.min(groupDiscount, subtotal);
		}

		if (appliedCoupon) {
			var base = subtotal - discount;
			var couponAmount = parseFloat(appliedCoupon.amount) || 0;
			var couponDiscount = appliedCoupon.type === "percentage" ? base * (couponAmount / 100) : couponAmount;
			discount += Math.min(round2(couponDiscount), base);
		}

		return {
			tickets: tickets,
			qty: qty,
			subtotal: round2(subtotal),
			discount: round2(discount),
			total: round2(Math.max(0, subtotal - discount))
		};
	}

	function recalculateTotal() {
		var $f = $form();
		if (!$f.length) {
			return;
		}

		current = computePricing();
		$f.attr("data-total", current.total);

		// Registration step: per-row totals and the tally.
		$f.find(".blt-ticket-type").each(function () {
			var $row = $(this);
			var $input = $row.find(".blt-ticket-quantity");
			if (!$input.length) {
				return;
			}
			var qty = parseInt($input.val(), 10) || 0;
			var price = parseFloat($input.data("price")) || 0;
			$row.find("[data-line-total]").text(formatPrice(qty * price));
			$row.toggleClass("is-selected", qty > 0);
		});
		$f.find("[data-blt-selected-count]").text(ticketCount(current.qty));
		$f.find("[data-blt-subtotal]").text(formatPrice(current.subtotal));
		if (current.qty > 0) {
			$f.find("[data-blt-step-error]").prop("hidden", true).text("");
		}

		// Legacy total element (theme overrides of the old template).
		$f.find(".blt-total-amount").text(ns.formatPrice ? ns.formatPrice(current.total, true) : current.total);

		// Payment only when something is owed. The card fields are disabled
		// otherwise so their `required` never blocks a free submission.
		var owes = current.total > 0;
		$("#blt-payment-section").prop("hidden", !owes).find("input").prop("disabled", !owes);

		rebuildAttendees();
		renderSummary();
		renderReview();

		$f.trigger("blt:totals", [current]);
	}

	api.pricing = function () {
		return current;
	};

	api.hasTickets = function () {
		return current.qty > 0;
	};

	$(document).on("change", "#blt-registration-form .blt-ticket-quantity", function () {
		var $input = $(this);
		var qty = parseInt($input.val(), 10) || 0;
		var max = parseInt($input.attr("max"), 10);
		if (qty < 0) {
			qty = 0;
		}
		if (!isNaN(max) && qty > max) {
			qty = max;
		}
		$input.val(qty);
		recalculateTotal();
	});

	/* ------------------------------------------------------------------
	 * Summary sidebar
	 * ---------------------------------------------------------------- */

	function renderSummary() {
		var $f = $form();
		var tickets = current.tickets;

		var $types = $f.find("[data-blt-summary-type-list]").empty();
		var $attendees = $f.find("[data-blt-summary-attendee-list]").empty();

		tickets.forEach(function (ticket) {
			$("<li>")
				.append($("<span>").text(ticket.name))
				.append($("<span>").text(priceOrFree(ticket.price)))
				.appendTo($types);
			$("<li>")
				.append($("<span>").text(ticket.qty + " × " + ticket.name))
				.append($("<span>").text(priceOrFree(ticket.qty * ticket.price)))
				.appendTo($attendees);
		});

		$f.find("[data-blt-summary-types], [data-blt-summary-attendees]").prop("hidden", !tickets.length);
		$f.find("[data-blt-summary-subtotal]").text(formatPrice(current.subtotal))
			.closest(".blt-summary__row").prop("hidden", !(current.subtotal > 0));
		$f.find("[data-blt-summary-discount-row]").prop("hidden", !(current.discount > 0));
		$f.find("[data-blt-summary-discount]").text("−" + formatPrice(current.discount));
		$f.find("[data-blt-summary-total]").text(priceOrFree(current.total));
	}

	/* ------------------------------------------------------------------
	 * Review screen
	 * ---------------------------------------------------------------- */

	function fieldValue($scope, name) {
		var $el = $scope.find('[name="' + name + '"]').filter(":not(:disabled)").first();
		return $el.length ? String($el.val() || "").trim() : "";
	}

	function personName($scope, prefix) {
		var p = prefix || "";
		var name = fieldValue($scope, p ? p + "[name]" : "name");
		if (!name) {
			name = [
				fieldValue($scope, p ? p + "[first_name]" : "first_name"),
				fieldValue($scope, p ? p + "[last_name]" : "last_name")
			].join(" ").trim();
		}
		return name;
	}

	function reviewAttendee(number, title, ticket, name, email) {
		var $li = $('<li class="blt-review-attendee">');
		$('<span class="blt-review-attendee__num">').text(number).appendTo($li);
		$('<span class="blt-review-attendee__who">')
			.append($("<strong>").text(title))
			.append($("<span>").text(ticket))
			.appendTo($li);
		$('<span class="blt-review-attendee__contact">')
			.append($("<span>").text(name || t("noName", "Name not given")))
			.append(email ? $('<span class="blt-review-attendee__email">').text(email) : null)
			.appendTo($li);
		return $li;
	}

	function renderReview() {
		var $f = $form();
		var $rows = $f.find("[data-blt-review-tickets]");
		if (!$rows.length) {
			return;
		}

		$rows.empty();
		current.tickets.forEach(function (ticket) {
			var $name = $('<span class="blt-review-table__name">').append($("<strong>").text(ticket.name));
			if (ticket.description) {
				$name.append($("<small>").text(ticket.description));
			}
			$('<div class="blt-review-table__row">')
				.append($name)
				.append($("<span>").text(priceOrFree(ticket.price)))
				.append($("<span>").text("× " + ticket.qty))
				.append($("<strong>").text(priceOrFree(ticket.qty * ticket.price)))
				.appendTo($rows);
		});

		$f.find("[data-blt-review-subtotal]").text(formatPrice(current.subtotal));
		$f.find("[data-blt-review-discount-row]").prop("hidden", !(current.discount > 0));
		$f.find("[data-blt-review-discount]").text("−" + formatPrice(current.discount));

		var $list = $f.find("[data-blt-review-attendees]").empty();
		var seats = seatList(current.tickets);
		var collecting = String($f.attr("data-collect-attendees")) === "1";
		var $primary = $f.find('[data-blt-attendee-card="primary"]');

		if (collecting) {
			$list.append(reviewAttendee(1, sprintf(t("attendeeN", "Attendee %d"), 1), seats[0] ? seats[0].name : "", personName($primary), fieldValue($primary, "email")));
			$f.find("[data-blt-attendee]").each(function (i) {
				var prefix = "attendees[" + $(this).attr("data-blt-attendee") + "]";
				var seat = seats[i + 1];
				$list.append(reviewAttendee(i + 2, sprintf(t("attendeeN", "Attendee %d"), i + 2), seat ? seat.name : "", personName($(this), prefix), fieldValue($(this), prefix + "[email]")));
			});
		} else {
			var what = current.tickets.map(function (ticket) {
				return ticket.qty + " × " + ticket.name;
			}).join(", ");
			$list.append(reviewAttendee(1, $primary.find(".blt-attendee-card__title").text().trim(), what, personName($primary), fieldValue($primary, "email")));
		}
	}

	api.renderReview = renderReview;

	/* ------------------------------------------------------------------
	 * Attendee cards
	 * ---------------------------------------------------------------- */

	/** One entry per purchased seat, in ticket order (matches the server). */
	function seatList(tickets) {
		var seats = [];
		tickets.forEach(function (ticket) {
			for (var n = 0; n < ticket.qty; n++) {
				seats.push({ index: ticket.index, name: ticket.name, price: ticket.price, key: ticket.index + ":" + n });
			}
		});
		return seats;
	}

	function snapshotCard($card, prefix) {
		var values = {};
		$card.find("input, select, textarea").each(function () {
			if (!this.name || $(this).is("[data-blt-attendee-ticket]")) {
				return;
			}
			var rel = this.name.indexOf(prefix) === 0 ? this.name.slice(prefix.length) : this.name;
			if (this.type === "checkbox" || this.type === "radio") {
				values[rel + "::" + this.value] = this.checked;
			} else {
				values[rel] = $(this).val();
			}
		});
		return values;
	}

	function restoreCard($card, prefix, values) {
		$card.find("input, select, textarea").each(function () {
			if (!this.name || $(this).is("[data-blt-attendee-ticket]")) {
				return;
			}
			var rel = this.name.indexOf(prefix) === 0 ? this.name.slice(prefix.length) : this.name;
			if (this.type === "checkbox" || this.type === "radio") {
				if (Object.prototype.hasOwnProperty.call(values, rel + "::" + this.value)) {
					this.checked = !!values[rel + "::" + this.value];
				}
			} else if (Object.prototype.hasOwnProperty.call(values, rel)) {
				$(this).val(values[rel]);
			}
		});
	}

	function snapshotAttendees($list) {
		var all = {};
		$list.find("[data-blt-attendee]").each(function () {
			var key = $(this).attr("data-seat-key");
			if (key) {
				all[key] = snapshotCard($(this), "attendees[" + $(this).attr("data-blt-attendee") + "]");
			}
		});
		return all;
	}

	function setSeat($card, seat, number, total) {
		$card.find("[data-blt-seat-ticket]").text(seat ? seat.name : "").prop("hidden", !seat);
		$card.find("[data-blt-seat-price]").text(seat ? priceOrFree(seat.price) : "");
		$card.find("[data-blt-seat-count]").text(sprintf(t("nOfTotal", "%1$d of %2$d"), number, total));
		$card.find("[data-blt-seat-foot]").prop("hidden", total < 2);
	}

	function rebuildAttendees() {
		var $f = $form();
		var seats = seatList(current.tickets);
		var total = seats.length;

		// The buyer's own card always holds seat one.
		var $primary = $f.find('[data-blt-attendee-card="primary"]');
		if (String($f.attr("data-collect-attendees")) === "1") {
			setSeat($primary, seats[0], 1, Math.max(total, 1));
		}

		var $list = $f.find("[data-blt-attendees-list]");
		if (!$list.length) {
			return;
		}

		var values = pendingAttendeeValues || snapshotAttendees($list);
		pendingAttendeeValues = null;

		var tmpl = $("#tmpl-blt-attendee").html() || "";
		$list.empty();

		seats.slice(1).forEach(function (seat, i) {
			var prefix = "attendees[" + i + "]";
			var $card = $(tmpl.replace(/__i__/g, String(i)));

			$card.attr("data-seat-key", seat.key);
			$card.find("[data-blt-attendee-title]").text(sprintf(t("attendeeN", "Attendee %d"), i + 2));
			setSeat($card, seat, i + 2, total);

			var $ticket = $card.find("[data-blt-attendee-ticket]");
			if ($ticket.is("select")) {
				// Theme overrides of the pre-2.5 template use a dropdown.
				$ticket.append($("<option>").val(seat.name).text(seat.name));
			}
			$ticket.val(seat.name);

			if (values[seat.key]) {
				restoreCard($card, prefix, values[seat.key]);
			}

			$list.append($card);
			applyConditions($card);
		});
	}

	// "Remove attendee": give back that seat's ticket and keep everyone
	// else's details on their own card.
	$(document).on("click", "#blt-registration-form [data-blt-remove-attendee]", function () {
		var $card = $(this).closest("[data-blt-attendee]");
		var key = String($card.attr("data-seat-key") || "");
		var parts = key.split(":");
		var $input = $form().find('.blt-ticket-quantity[data-index="' + parts[0] + '"]');
		if (parts.length !== 2 || !$input.length) {
			return;
		}

		var position = $card.index();
		var removed = parseInt(parts[1], 10);
		var values = snapshotAttendees($card.parent());
		var shifted = {};

		Object.keys(values).forEach(function (k) {
			var p = k.split(":");
			var n = parseInt(p[1], 10);
			if (p[0] !== parts[0] || n < removed) {
				shifted[k] = values[k];
			} else if (n > removed) {
				shifted[p[0] + ":" + (n - 1)] = values[k];
			}
		});

		pendingAttendeeValues = shifted;
		$input.val(Math.max(0, (parseInt($input.val(), 10) || 0) - 1)).trigger("change");

		// Keep keyboard focus nearby.
		var $cards = $form().find("[data-blt-attendee]");
		var $next = $cards.eq(Math.min(position, $cards.length - 1));
		var $title = ($next.length ? $next : $form().find('[data-blt-attendee-card="primary"]')).find(".blt-attendee-card__title");
		$title.attr("tabindex", "-1").trigger("focus");
	});

	/* ------------------------------------------------------------------
	 * Conditional fields
	 * ---------------------------------------------------------------- */

	function fieldValues($scope, key, prefix) {
		var name = prefix ? prefix + "[" + key + "]" : key;
		var values = [];
		var $inputs = $scope.find('[name="' + name + '"], [name="' + name + '[]"]');

		$inputs.each(function () {
			var type = (this.type || "").toLowerCase();
			if (type === "checkbox" || type === "radio") {
				if (this.checked) {
					values.push(String(this.value));
				}
			} else if (String(this.value).trim() !== "") {
				values.push(String(this.value).trim());
			}
		});

		return values;
	}

	function conditionMet(cond, $scope) {
		var values = fieldValues($scope, cond.field, cond.prefix || "");
		var expected = String(cond.value || "");

		switch (cond.operator) {
			case "is":
				return values.indexOf(expected) !== -1;
			case "is_not":
				return values.indexOf(expected) === -1;
			case "contains":
				return values.some(function (v) {
					return expected !== "" && v.toLowerCase().indexOf(expected.toLowerCase()) !== -1;
				});
			case "not_empty":
				return values.length > 0;
			case "empty":
				return values.length === 0;
		}
		return true;
	}

	function applyConditions($scope) {
		$scope.find("[data-blt-condition]").each(function () {
			var $wrap = $(this);
			var cond;
			try {
				cond = JSON.parse($wrap.attr("data-blt-condition"));
			} catch (e) {
				return;
			}

			// Field names are unique across the form, attendee cards included.
			var $owner = $wrap.closest("form");
			var show = conditionMet(cond, $owner.length ? $owner : $scope);
			$wrap.prop("hidden", !show);
			// Disabled controls are neither validated nor submitted.
			$wrap.find("input, select, textarea").prop("disabled", !show);
		});
	}

	$(document).on("change input", "#blt-registration-form", function () {
		applyConditions($(this));
	});

	// Keep the review screen current while details are typed.
	$(document).on("change", "#blt-registration-form .blt-reg__details", renderReview);

	$(function () {
		var $f = $form();
		if ($f.length) {
			applyConditions($f);
			recalculateTotal();
		}
	});

	/* ------------------------------------------------------------------
	 * Coupon application
	 * ---------------------------------------------------------------- */

	$(document).on("click", "#blt-apply-coupon", function () {
		var code = $("#coupon_code").val().trim();
		var $msg = $("#blt-coupon-message");
		if (!code) {
			return;
		}

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "blt_validate_coupon",
				nonce: data.nonce,
				coupon_code: code,
				event_id: data.eventId,
				quantity: current.qty
			},
			success: function (response) {
				if (response.success) {
					appliedCoupon = response.data;
					showMessage($msg, sprintf(t("couponApplied", "Coupon applied: %s"), response.data.label), "success");
				} else {
					appliedCoupon = null;
					showMessage($msg, response.data.message, "error");
				}
				recalculateTotal();
			},
			error: function () {
				appliedCoupon = null;
				showMessage($msg, t("genericError", "An error occurred. Please try again."), "error");
			}
		});
	});

	// Enter in the coupon box applies the coupon instead of moving on.
	$(document).on("keydown", "#coupon_code", function (e) {
		if (e.key === "Enter") {
			e.preventDefault();
			$("#blt-apply-coupon").trigger("click");
		}
	});

	/* ------------------------------------------------------------------
	 * Client-side checks HTML5 validation cannot express
	 * ---------------------------------------------------------------- */

	function checkChoiceGroups($scope) {
		var ok = true;
		$scope.find('.blt-choice-group[data-blt-required="1"]').each(function () {
			var $group = $(this);
			if ($group.closest("[hidden]").length) {
				return;
			}
			if (!$group.find('input[type="checkbox"]:checked').length) {
				ok = false;
				$group.addClass("blt-invalid");
				$group.find("input").first().trigger("focus");
				return false;
			}
			$group.removeClass("blt-invalid");
		});
		if (!ok) {
			showMessage($("#blt-form-messages"), t("chooseAtLeast", "Please choose at least one option."), "error");
		}
		return ok;
	}

	api.checkChoiceGroups = checkChoiceGroups;

	/* ------------------------------------------------------------------
	 * Form submission (free registrations)
	 * ---------------------------------------------------------------- */

	$(document).on("submit", "#blt-registration-form", function (e) {
		e.preventDefault();

		var $f = $(this);
		var $msg = $("#blt-form-messages");

		// Card payments are payment.js's. Without an on-site card form the
		// server answers a paid selection with its "requires payment" message.
		if (current.total > 0 && data.provider === "stripe" && String($f.attr("data-takes-payment")) === "1") {
			return;
		}

		if (!checkChoiceGroups($f)) {
			return;
		}

		if (this.reportValidity && !this.reportValidity()) {
			return;
		}

		var $btn = $("#blt-submit-btn");
		$btn.prop("disabled", true);
		setSubmitLabel(t("registering", "Registering…"));
		$msg.prop("hidden", true);

		var formData = $f.serializeArray();
		formData.push({ name: "action", value: "blt_register" });
		formData.push({ name: "nonce", value: data.nonce });

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: $.param(formData),
			success: function (response) {
				if (response.success) {
					showMessage($msg, response.data.message, "success");
					$f.find("fieldset, input, select, textarea, button").prop("disabled", true);
					setSubmitLabel(t("complete", "Registration Complete"));
					$f.trigger("blt:registered", [response.data]);
				} else {
					showMessage($msg, response.data.message, "error");
					$btn.prop("disabled", false);
					restoreSubmitLabel();
				}
			},
			error: function () {
				showMessage($msg, t("genericError", "An error occurred. Please try again."), "error");
				$btn.prop("disabled", false);
				restoreSubmitLabel();
			}
		});
	});

})(jQuery);
