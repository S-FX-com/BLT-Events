/**
 * BLT Events - Registration Form JavaScript
 *
 * Handles ticket quantity changes, total calculation, coupon application,
 * per-attendee detail blocks, conditional fields, and submission of free
 * registrations via AJAX. Paid Stripe registrations are handed over to
 * payment.js.
 *
 * Every visible string comes from bltRegData.i18n (localized in PHP).
 */
(function ($) {
	"use strict";

	var data = window.bltRegData || {};
	var i18n = data.i18n || {};
	var totalPrice = 0;
	var appliedCoupon = null;

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	function formatPrice(amount, includeTotal) {
		if (window.bltEvents && typeof window.bltEvents.formatPrice === "function") {
			return window.bltEvents.formatPrice(amount, includeTotal);
		}
		return (includeTotal ? "Total: " : "") + parseFloat(amount || 0).toFixed(2);
	}

	function sprintf(str, value) {
		return String(str).replace(/%(\d+\$)?[ds]/, value);
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

	/* ------------------------------------------------------------------
	 * Tickets & totals
	 * ---------------------------------------------------------------- */

	function selectedTickets() {
		var tickets = [];
		$(".blt-registration-form .blt-ticket-quantity").each(function () {
			var $input = $(this);
			var qty = parseInt($input.val(), 10) || 0;
			if (qty > 0) {
				tickets.push({
					index: $input.data("index"),
					name: String($input.data("name") || ""),
					price: parseFloat($input.data("price")) || 0,
					qty: qty
				});
			}
		});
		return tickets;
	}

	function recalculateTotal() {
		var total = 0;
		var hasTickets = false;

		selectedTickets().forEach(function (ticket) {
			total += ticket.qty * ticket.price;
			hasTickets = true;
		});

		// Apply coupon discount to the displayed total (the server
		// recomputes the authoritative amount at checkout).
		if (appliedCoupon) {
			var amount = parseFloat(appliedCoupon.amount) || 0;
			var discount = appliedCoupon.type === "percentage" ? total * (amount / 100) : amount;
			total = Math.max(0, total - discount);
		}

		totalPrice = Math.round(total * 100) / 100;

		$(".blt-registration-form .blt-total-amount").text(formatPrice(totalPrice, true));

		var $btn = $("#blt-submit-btn");
		var $form = $("#blt-registration-form");
		var stepped = $form.data("stepped") === 1 || $form.data("stepped") === "1";

		if (!stepped) {
			$btn.prop("disabled", false).text(t("registerFree", "Register — Free"));
		} else if (hasTickets) {
			$btn.prop("disabled", false).text(totalPrice > 0 ? t("registerPay", "Register & Pay") : t("registerFree", "Register — Free"));
		} else {
			$btn.prop("disabled", true).text(t("selectToContinue", "Select tickets to continue"));
		}

		// Payment section only when something is owed.
		$("#blt-payment-section").prop("hidden", !(totalPrice > 0));

		rebuildAttendees();
	}

	$(document).on("change", ".blt-registration-form .blt-ticket-quantity", recalculateTotal);

	/* ------------------------------------------------------------------
	 * Additional attendees
	 * ---------------------------------------------------------------- */

	function rebuildAttendees() {
		var $section = $("[data-blt-attendees]");
		if (!$section.length) {
			return;
		}

		var $list = $section.find("[data-blt-attendees-list]");
		var tickets = selectedTickets();
		var total = 0;
		var seats = [];

		tickets.forEach(function (ticket) {
			total += ticket.qty;
			for (var n = 0; n < ticket.qty; n++) {
				seats.push(ticket.name);
			}
		});

		// Seat 0 belongs to the person filling in the form.
		seats.shift();
		var extra = Math.max(0, total - 1);

		// Keep what was typed when quantities change.
		var previous = {};
		$list.find("[data-blt-attendee]").each(function () {
			var idx = $(this).attr("data-blt-attendee");
			previous[idx] = {};
			$(this).find("input, select, textarea").each(function () {
				previous[idx][this.name] = $(this).val();
			});
		});

		$list.empty();

		var tmpl = $("#tmpl-blt-attendee").html() || "";

		for (var i = 0; i < extra; i++) {
			var $block = $(tmpl.replace(/__i__/g, String(i)));
			$block.find("[data-blt-attendee-title]").text(sprintf(t("attendeeN", "Attendee %d"), i + 2));

			var $select = $block.find("[data-blt-attendee-ticket]");
			tickets.forEach(function (ticket) {
				$select.append($("<option>").val(ticket.name).text(ticket.name));
			});
			if (seats[i]) {
				$select.val(seats[i]);
			}

			if (previous[String(i)]) {
				$block.find("input, select, textarea").each(function () {
					if (Object.prototype.hasOwnProperty.call(previous[String(i)], this.name)) {
						$(this).val(previous[String(i)][this.name]);
					}
				});
			}

			$list.append($block);
		}

		$section.prop("hidden", extra === 0);
		$section.find("input, select, textarea").prop("disabled", extra === 0);
	}

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

			var show = conditionMet(cond, $scope);
			$wrap.prop("hidden", !show);
			// Disabled controls are neither validated nor submitted.
			$wrap.find("input, select, textarea").prop("disabled", !show);
		});
	}

	$(document).on("change input", "#blt-registration-form", function () {
		applyConditions($(this));
	});

	$(function () {
		var $form = $("#blt-registration-form");
		if ($form.length) {
			applyConditions($form);
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

		var totalQty = 0;
		selectedTickets().forEach(function (ticket) {
			totalQty += ticket.qty;
		});

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "blt_validate_coupon",
				nonce: data.nonce,
				coupon_code: code,
				event_id: data.eventId,
				quantity: totalQty
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

	/* ------------------------------------------------------------------
	 * Client-side checks HTML5 validation cannot express
	 * ---------------------------------------------------------------- */

	function checkChoiceGroups($form) {
		var ok = true;
		$form.find('.blt-choice-group[data-blt-required="1"]').each(function () {
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
		return ok;
	}

	/* ------------------------------------------------------------------
	 * Form submission (free registrations)
	 * ---------------------------------------------------------------- */

	$(document).on("submit", "#blt-registration-form", function (e) {
		e.preventDefault();

		var $form = $(this);
		var $msg = $("#blt-form-messages");

		// For paid events with Stripe, payment.js handles submission.
		if (totalPrice > 0 && data.provider === "stripe") {
			return;
		}

		if (!checkChoiceGroups($form)) {
			showMessage($msg, t("chooseAtLeast", "Please choose at least one option."), "error");
			return;
		}

		if (this.reportValidity && !this.reportValidity()) {
			return;
		}

		var $btn = $("#blt-submit-btn");
		$btn.prop("disabled", true).text(t("registering", "Registering…"));

		var formData = $form.serializeArray();
		formData.push({ name: "action", value: "blt_register" });
		formData.push({ name: "nonce", value: data.nonce });

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: $.param(formData),
			success: function (response) {
				if (response.success) {
					showMessage($msg, response.data.message, "success");
					$form.find("fieldset, input, select, textarea, button").prop("disabled", true);
					$btn.text(t("complete", "Registration Complete"));
					$form.trigger("blt:registered", [response.data]);
				} else {
					showMessage($msg, response.data.message, "error");
					$btn.prop("disabled", false).text(t("registerFree", "Register — Free"));
				}
			},
			error: function () {
				showMessage($msg, t("genericError", "An error occurred. Please try again."), "error");
				$btn.prop("disabled", false).text(t("registerFree", "Register — Free"));
			}
		});
	});

})(jQuery);
