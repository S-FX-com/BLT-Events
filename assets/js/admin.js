/**
 * BLT Events - Admin JavaScript
 *
 * Handles admin UI interactions: coupon code generation, payment provider toggle, etc.
 */
jQuery(document).ready(function ($) {
	"use strict";

	// Generate random coupon code
	$(document).on("click", "#generate_coupon_code", function () {
		var code = Math.random().toString(36).substring(2, 10).toUpperCase();
		$("#coupon_code").val(code);
	});

	// Update amount symbol based on discount type
	$(document).on("change", "#discount_type", function () {
		if ($(this).val() === "percentage") {
			$("#amount_symbol").text("%");
		} else {
			$("#amount_symbol").text("$");
		}
	});

	// Payment provider toggle on settings page
	$('input[name="blt_events_payment_provider"]').on("change", function () {
		var provider = $(this).val();
		$(".blt-provider-stripe, .blt-provider-surecart").hide();
		if (provider !== "none") {
			$(".blt-provider-" + provider).show();
		}
	});

	/* ----------------------------------------------------------------
	 * Event editor: conditional fields
	 * ---------------------------------------------------------------- */

	// Show only the location fields relevant to the selected event type:
	// in-person → venue + address, online → registration/webinar link,
	// hybrid → both.
	var $eventType = $("#event_type");
	var $meetingAuto = $("#blt-meeting-auto");
	var $meetingProvider = $("#meeting_provider");

	var isOnlineEvent = function () {
		var type = $eventType.val();
		return type === "online" || type === "hybrid";
	};

	// The meeting options (provider, room type, existing room) only apply to an
	// online/hybrid event with auto-create enabled; the room-type row only
	// applies to providers that distinguish webinars from meetings.
	var updateMeetingOptions = function () {
		var show = isOnlineEvent() && $meetingAuto.is(":checked");
		$(".blt-meeting-options").toggle(show);
		if (show) {
			var supportsWebinars =
				$meetingProvider.find("option:selected").data("webinars") == 1;
			$(".blt-meeting-type-row").toggle(!!supportsWebinars);
		}
	};

	if ($eventType.length) {
		var toggleEventTypeFields = function () {
			var type = $eventType.val();
			$(".blt-field-physical").toggle(type === "in-person" || type === "hybrid");
			$(".blt-field-online").toggle(type === "online" || type === "hybrid");
			updateMeetingOptions();
		};
		toggleEventTypeFields();
		$eventType.on("change", toggleEventTypeFields);
	}

	$meetingAuto.on("change", updateMeetingOptions);
	$meetingProvider.on("change", updateMeetingOptions);

	// All-day events don't need start/end times.
	$('input[name="event_all_day"]').on("change", function () {
		$(".blt-time-row").toggle(!this.checked);
	});

	// The end date can never precede the start date.
	var syncEndDateMin = function () {
		var start = $("#event_date").val();
		var $end = $("#event_end_date");
		if (!$end.length) {
			return;
		}
		if (start) {
			$end.attr("min", start);
			if ($end.val() && $end.val() < start) {
				$end.val("");
			}
		} else {
			$end.removeAttr("min");
		}
	};
	syncEndDateMin();
	$("#event_date").on("change", syncEndDateMin);

	/* ----------------------------------------------------------------
	 * Event editor: address autocomplete (OpenStreetMap Nominatim)
	 * ---------------------------------------------------------------- */
	var $address = $("#event_location");
	if ($address.length && $address.closest(".blt-address-autocomplete").length) {
		var $list = $("#blt-address-suggestions");
		var searchTimer = null;
		var activeRequest = null;
		var activeIndex = -1;

		var clearCoords = function () {
			$("#event_latitude").val("");
			$("#event_longitude").val("");
		};

		var closeList = function () {
			$list.hide().empty();
			$address.attr("aria-expanded", "false");
			activeIndex = -1;
		};

		var selectSuggestion = function ($item) {
			$address.val($item.attr("data-address"));
			$("#event_latitude").val($item.attr("data-lat"));
			$("#event_longitude").val($item.attr("data-lon"));
			closeList();
		};

		var renderSuggestions = function (results) {
			$list.empty();
			if (!results.length) {
				closeList();
				return;
			}
			$.each(results, function (i, place) {
				$("<li>", {
					"class": "blt-address-suggestion",
					role: "option",
					text: place.display_name,
					"data-address": place.display_name,
					"data-lat": place.lat,
					"data-lon": place.lon,
				}).appendTo($list);
			});
			activeIndex = -1;
			$list.show();
			$address.attr("aria-expanded", "true");
		};

		var highlight = function (index) {
			var $items = $list.children();
			$items.removeClass("is-active");
			if (index >= 0 && index < $items.length) {
				$items.eq(index).addClass("is-active");
			}
			activeIndex = index;
		};

		$address.on("input", function () {
			// The stored coordinates belong to a picked suggestion; once the
			// address is edited by hand they no longer match.
			clearCoords();

			var query = $.trim($address.val());
			clearTimeout(searchTimer);
			if (activeRequest) {
				activeRequest.abort();
				activeRequest = null;
			}
			if (query.length < 3) {
				closeList();
				return;
			}
			searchTimer = setTimeout(function () {
				activeRequest = $.getJSON("https://nominatim.openstreetmap.org/search", {
					format: "jsonv2",
					limit: 5,
					q: query,
				})
					.done(renderSuggestions)
					.fail(function (xhr, status) {
						if (status !== "abort") {
							closeList();
						}
					})
					.always(function () {
						activeRequest = null;
					});
			}, 500);
		});

		$address.on("keydown", function (e) {
			if (!$list.is(":visible")) {
				return;
			}
			var count = $list.children().length;
			if (e.key === "ArrowDown") {
				e.preventDefault();
				highlight((activeIndex + 1) % count);
			} else if (e.key === "ArrowUp") {
				e.preventDefault();
				highlight((activeIndex - 1 + count) % count);
			} else if (e.key === "Enter") {
				if (activeIndex >= 0) {
					e.preventDefault();
					selectSuggestion($list.children().eq(activeIndex));
				}
			} else if (e.key === "Escape") {
				closeList();
			}
		});

		$list.on("mousedown", ".blt-address-suggestion", function (e) {
			e.preventDefault(); // Keep focus on the input.
			selectSuggestion($(this));
		});

		$(document).on("click", function (e) {
			if (!$(e.target).closest(".blt-address-autocomplete").length) {
				closeList();
			}
		});
	}
});
