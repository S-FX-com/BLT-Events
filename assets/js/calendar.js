(function ($) {
	"use strict";

	function request($calendar, state) {
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
			}
		}).always(function () {
			$calendar.removeClass("is-loading");
		});
	}

	$(function () {
		$(".blt-events-listing").each(function () {
			var $calendar = $(this);
			var $search = $calendar.find('input[name="blt_search"]');
			var $range = $calendar.find(".blt-list-range");
			var currentRange = $range.data("current") || "today";
			var timer;

			function state(paged) {
				return {
					search: $search.val() || "",
					range: currentRange,
					paged: paged || 1
				};
			}

			$calendar.on("input", 'input[name="blt_search"]', function () {
				window.clearTimeout(timer);
				timer = window.setTimeout(function () {
					request($calendar, state(1));
				}, 220);
			});

			$calendar.on("click", ".blt-list-range a", function (event) {
				event.preventDefault();

				var $link = $(this);

				currentRange = $link.data("range");
				$range.attr("data-current", currentRange).removeAttr("open");
				$range.find(".blt-list-range-label").text($link.text());
				$range.find("a").removeClass("is-active");
				$link.addClass("is-active");

				request($calendar, state(1));
			});

			$calendar.on("submit", ".blt-list-search", function (event) {
				event.preventDefault();
				request($calendar, state(1));
			});

			$calendar.on("click", ".blt-list-navbtn:not(.is-disabled)", function (event) {
				var href = $(this).attr("href") || "";
				var match = href.match(/[?&]blt_paged=(\d+)/);

				if (!match) {
					return;
				}

				event.preventDefault();
				request($calendar, state(parseInt(match[1], 10)));
			});
		});
	});
})(jQuery);
