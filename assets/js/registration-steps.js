/**
 * BLT Events - Registration steps.
 *
 * Drives the checkout through Registration (tickets) -> Attendee details ->
 * Review & payment. The review step only exists while something is owed:
 * a free order completes straight from Attendee details. Events without
 * ticket types start on Attendee details.
 *
 * Panels declare the steps they belong to with data-step-panel (space
 * separated); the form's data-current-step decides which are visible.
 * registration-form.js owns totals and submission; this script only moves
 * between steps, validates each one before leaving it, and labels the
 * primary button.
 */
(function ($) {
	"use strict";

	$(function () {
		var i18n = (window.bltRegData || {}).i18n || {};
		var ns = (window.bltEvents = window.bltEvents || {});
		var api = (ns.registration = ns.registration || {});

		var $form = $("#blt-registration-form");
		if (!$form.length || !$form.find("[data-step-panel]").length) {
			return;
		}

		var stepped = String($form.attr("data-stepped")) === "1";
		var takesPayment = String($form.attr("data-takes-payment")) === "1";
		var current = $form.attr("data-current-step") || (stepped ? "tickets" : "details");

		function owes() {
			return (parseFloat($form.attr("data-total")) || 0) > 0;
		}

		// Before anything is picked, a paid event shows the payment step it
		// will most likely need, so the progress bar doesn't grow later.
		function steps() {
			var list = stepped ? ["tickets", "details"] : ["details"];
			var nothingPicked = typeof api.hasTickets === "function" && !api.hasTickets();
			if (owes() || (nothingPicked && takesPayment)) {
				list.push("review");
			}
			return list;
		}

		function isFinal(step) {
			var list = steps();
			return list[list.length - 1] === step;
		}

		function submitLabel() {
			if (current === "details" && !isFinal("details")) {
				return i18n.toReview || "Continue to review & payment";
			}
			if (current === "review" && owes()) {
				return i18n.completePayment || "Complete payment";
			}
			return i18n.completeFree || "Complete registration";
		}

		api.submitLabel = submitLabel;

		function refreshLabel() {
			var $btn = $form.find("#blt-submit-btn");
			if ($btn.length && !$btn.prop("disabled") && typeof api.setSubmitLabel === "function") {
				api.setSubmitLabel(submitLabel());
			}
		}

		function stepError(text) {
			var $error = $form.find('[data-step-panel~="' + current + '"] [data-blt-step-error]').first();
			if ($error.length) {
				$error.text(text || "").prop("hidden", !text);
			} else if (text) {
				window.alert(text);
			}
		}

		function show(step, moveFocus) {
			current = step;
			$form.attr("data-current-step", step);

			$form.find("[data-step-panel]").each(function () {
				var panelSteps = String($(this).attr("data-step-panel")).split(/\s+/);
				this.hidden = panelSteps.indexOf(step) === -1;
			});

			var order = steps();
			var at = order.indexOf(step);
			$form.find("[data-progress]").each(function () {
				var name = $(this).attr("data-progress");
				var k = order.indexOf(name);
				this.hidden = k === -1;
				$(this)
					.toggleClass("is-current", name === step)
					.toggleClass("is-done", step === "complete" || (k !== -1 && k < at));
				if (name === step) {
					this.setAttribute("aria-current", "step");
				} else {
					this.removeAttribute("aria-current");
				}
			});

			refreshLabel();
			if (step === "review" && typeof api.renderReview === "function") {
				api.renderReview();
			}

			if (moveFocus) {
				var target = $form.find('[data-step-panel~="' + step + '"] .blt-reg__title').get(0);
				if (target) {
					target.setAttribute("tabindex", "-1");
					target.focus({ preventScroll: true });
				}

				// Bring the top of the checkout back into view when a step
				// change happened further down the page.
				var top = $form.closest(".blt-registration-form").offset().top - 24;
				if (window.pageYOffset > top) {
					window.scrollTo({ top: top, behavior: "smooth" });
				}
			}

			$form.trigger("blt:step", [step]);
		}

		function validate(step) {
			var $panels = $form.find('[data-step-panel~="' + step + '"]');
			stepError("");

			if (step === "tickets" && typeof api.hasTickets === "function" && !api.hasTickets()) {
				stepError(i18n.selectTickets || "Please select at least one ticket to continue.");
				return false;
			}

			var ok = true;
			$panels.find("input, select, textarea").each(function () {
				if (this.willValidate && !this.checkValidity()) {
					if (typeof this.reportValidity === "function") {
						this.reportValidity();
					}
					ok = false;
					return false;
				}
			});

			if (ok && typeof api.checkChoiceGroups === "function") {
				ok = api.checkChoiceGroups($panels);
			}

			return ok;
		}

		// Directly on the form, so it runs before the document-level submit
		// handlers in registration-form.js and payment.js and can keep them
		// from firing until the final step.
		$form.on("submit", function (e) {
			if (current === "complete") {
				e.preventDefault();
				e.stopPropagation();
				return;
			}

			if (isFinal(current)) {
				if (!validate(current)) {
					e.preventDefault();
					e.stopPropagation();
				}
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			if (validate(current)) {
				var order = steps();
				show(order[order.indexOf(current) + 1], true);
			}
		});

		// Back links and "Edit" buttons only ever go to an earlier step.
		$form.on("click", "[data-blt-go]", function () {
			var target = $(this).attr("data-blt-go");
			if (steps().indexOf(target) !== -1) {
				show(target, true);
			}
		});

		// Totals changed (tickets, coupon): the review step may have appeared
		// or disappeared, and the button label may need to change with it.
		$form.on("blt:totals", function () {
			if (current !== "complete") {
				show(current, false);
			}
		});

		$form.on("blt:registered", function (e, response) {
			var result = response || {};
			$form.find("[data-blt-complete-title]").text(
				result.status === "pending"
					? i18n.pendingTitle || "Registration received"
					: i18n.completeTitle || "You are registered"
			);
			$form.find("[data-blt-complete-message]").text(result.message || "");
			show("complete", true);
		});

		show(current, false);
	});
})(jQuery);
