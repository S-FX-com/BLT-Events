(function ($) {
	"use strict";

	/**
	 * Enable/disable the prev/next buttons to match the page the AJAX
	 * response actually rendered — the toolbar itself is only rendered once,
	 * on the initial page load, so this is the only thing that keeps their
	 * state in sync after that.
	 */
	function updateNav($calendar, hasPrev, hasNext) {
		$calendar.find(".blt-list-navbtn--prev")
			.toggleClass("is-disabled", !hasPrev)
			.attr("aria-disabled", hasPrev ? null : "true");
		$calendar.find(".blt-list-navbtn--next")
			.toggleClass("is-disabled", !hasNext)
			.attr("aria-disabled", hasNext ? null : "true");
	}

	function request($calendar, state, onDone) {
		var $results = $calendar.find(".blt-list-results");
		var data = window.bltCalendarData || {};

		if (!data.ajaxUrl || !data.nonce) {
			return;
		}

		$calendar.addClass("is-loading");
		$.post(data.ajaxUrl, {
			action: "blt_filter_events",
			nonce: data.nonce,
			search: state.search,
			range: state.range,
			paged: state.paged,
			category: $calendar.data("category") || "",
			featured: $calendar.data("featured") || "no",
			past: $calendar.data("past") || "no",
			limit: $calendar.data("limit") || 12
		}).done(function (response) {
			if (response && response.success && response.data && response.data.html) {
				$results.html(response.data.html);
				updateNav($calendar, !!response.data.hasPrev, !!response.data.hasNext);
				if (onDone) {
					onDone();
				}
			}
		}).always(function () {
			$calendar.removeClass("is-loading");
		});
	}

	$(function () {
		$(".blt-events-listing").each(function () {
			var $calendar = $(this);
			var $search = $calendar.find('input[name="blt_search"]');
			var $range = $calendar.find('select[name="blt_range"]');
			var timer;
			// The toolbar (and its prev/next hrefs) is only ever rendered
			// once, server-side, on the initial load — so the current page
			// has to be tracked here instead of re-read from a link's href
			// after that, or it goes stale after the first AJAX navigation.
			var currentPage = parseInt($calendar.data("paged"), 10) || 1;

			function state(paged) {
				return {
					search: $search.val() || "",
					range: $range.val() || "all",
					paged: paged || 1
				};
			}

			$calendar.on("input", 'input[name="blt_search"]', function () {
				window.clearTimeout(timer);
				timer = window.setTimeout(function () {
					currentPage = 1;
					request($calendar, state(1));
				}, 220);
			});

			$calendar.on("change", 'select[name="blt_range"]', function () {
				currentPage = 1;
				request($calendar, state(1));
			});

			$calendar.on("submit", ".blt-list-search", function (event) {
				event.preventDefault();
				currentPage = 1;
				request($calendar, state(1));
			});

			$calendar.on("click", ".blt-list-navbtn--prev:not(.is-disabled)", function (event) {
				event.preventDefault();
				var targetPage = currentPage - 1;
				request($calendar, state(targetPage), function () {
					currentPage = targetPage;
				});
			});

			$calendar.on("click", ".blt-list-navbtn--next:not(.is-disabled)", function (event) {
				event.preventDefault();
				var targetPage = currentPage + 1;
				request($calendar, state(targetPage), function () {
					currentPage = targetPage;
				});
			});
		});
	});
})(jQuery);
