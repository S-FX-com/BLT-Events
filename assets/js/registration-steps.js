/**
 * BLT Events - Registration wizard.
 *
 * Turns the registration form into a stepped flow (tickets -> details)
 * when the form is marked data-stepped. Purely a UI layer on top of the
 * existing form: registration-form.js still owns totals and submission.
 */
(function ($) {
	"use strict";

	$(function () {
		var i18n = ( window.bltRegData || {} ).i18n || {};

		$('.blt-reg[data-stepped="1"]').each(function () {
			var $form = $(this);
			var $steps = $form.find(".blt-reg__step");
			if ($steps.length < 2) {
				return;
			}

			var $back = $form.find(".blt-reg__back");
			var $next = $form.find(".blt-reg__next");
			var $progress = $form.find(".blt-reg__progress-step");
			var idx = 0;

			// Hiding is CSS-driven once JS is active, so the steps don't
			// flash all-open before this runs.
			$form.addClass("blt-reg--js");

			function ticketsChosen() {
				var total = 0;
				$form.find(".blt-ticket-quantity").each(function () {
					total += parseInt($(this).val(), 10) || 0;
				});
				return total > 0;
			}

			function validateStep($step) {
				if ($step.hasClass("blt-ticket-selection") && !ticketsChosen()) {
					window.alert(i18n.selectTickets || "Please select at least one ticket to continue.");
					return false;
				}

				var ok = true;
				$step.find("input, select, textarea").each(function () {
					if (this.willValidate && !this.checkValidity()) {
						if (typeof this.reportValidity === "function") {
							this.reportValidity();
						}
						ok = false;
						return false;
					}
				});
				return ok;
			}

			function go(n) {
				idx = Math.max(0, Math.min(n, $steps.length - 1));

				$steps.each(function (k) {
					$(this).toggle(k === idx);
				});
				$back.prop("hidden", idx === 0);
				$next.prop("hidden", idx === $steps.length - 1);

				$progress.each(function (k) {
					$(this)
						.toggleClass("is-current", k === idx)
						.toggleClass("is-done", k < idx);
				});

				var stepEl = $steps.get(idx);
				if (stepEl && typeof stepEl.focus === "function") {
					stepEl.setAttribute("tabindex", "-1");
					stepEl.focus({ preventScroll: true });
				}
			}

			$next.on("click", function () {
				if (validateStep($steps.eq(idx))) {
					go(idx + 1);
				}
			});

			$back.on("click", function () {
				go(idx - 1);
			});

			go(0);
		});
	});
})(jQuery);
