/**
 * BLT Events - Event Editor JavaScript
 *
 * Interactions for the Add/Edit Event screen: date/time toggles, the
 * event type selector, meeting options, address autocomplete with map
 * preview, ticket type rows, and registration configuration.
 */
jQuery(document).ready(function ($) {
	"use strict";

	var i18n = window.bltEventEditor || {};

	/* ----------------------------------------------------------------
	 * Event details: all day / no end time
	 * ---------------------------------------------------------------- */
	var $allDay = $("#blt-all-day");
	var $noEnd = $("#blt-no-end-time");

	var updateDetailFields = function () {
		var allDay = $allDay.is(":checked");
		var noEnd = $noEnd.is(":checked");

		$(".blt-field-start-time").toggle(!allDay);
		$(".blt-field-end-date").toggle(!noEnd);
		$(".blt-field-end-time").toggle(!noEnd && !allDay);

		var $notice = $("#blt-details-notice");
		if (allDay) {
			$notice.show().find(".blt-notice-text").text(i18n.allDayNotice || "");
		} else if (noEnd) {
			$notice.show().find(".blt-notice-text").text(i18n.noEndNotice || "");
		} else {
			$notice.hide();
		}
	};

	$allDay.on("change", function () {
		if (this.checked) {
			$noEnd.prop("checked", false);
		}
		updateDetailFields();
	});

	$noEnd.on("change", function () {
		if (this.checked) {
			$allDay.prop("checked", false);
		}
		updateDetailFields();
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
	 * Event type selector + conditional panels
	 * ---------------------------------------------------------------- */
	var $typeRadios = $('input[name="event_type"]');
	var $meetingAuto = $("#blt-meeting-auto");
	var $meetingProvider = $("#meeting_provider");

	var currentType = function () {
		return $typeRadios.filter(":checked").val() || "in-person";
	};

	var isOnlineEvent = function () {
		var type = currentType();
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

	var updateTypePanels = function () {
		var type = currentType();
		$typeRadios.each(function () {
			$(this).closest(".blt-segment").toggleClass("is-active", this.checked);
		});
		$(".blt-panel-online").toggle(type === "online" || type === "hybrid");
		$(".blt-panel-venue").toggle(type === "in-person" || type === "hybrid");
		updateMeetingOptions();
	};

	if ($typeRadios.length) {
		$typeRadios.on("change", updateTypePanels);
		updateTypePanels();
	}

	$meetingAuto.on("change", updateMeetingOptions);
	$meetingProvider.on("change", updateMeetingOptions);

	/* ----------------------------------------------------------------
	 * Address autocomplete (OpenStreetMap Nominatim) + map preview
	 * ---------------------------------------------------------------- */
	var updateMapPreview = function (lat, lon) {
		var $preview = $("#blt-map-preview");
		if (!$preview.length) {
			return;
		}
		if (lat === "" || lon === "") {
			if (!$preview.find(".blt-map-placeholder").length) {
				$preview.empty().append(
					$("<span>", { "class": "blt-map-placeholder" })
						.append($("<span>", { "class": "dashicons dashicons-location" }))
						.append(document.createTextNode(" " + (i18n.mapPlaceholder || "")))
				);
			}
			return;
		}
		var latNum = parseFloat(lat);
		var lonNum = parseFloat(lon);
		var bbox = [lonNum - 0.005, latNum - 0.003, lonNum + 0.005, latNum + 0.003].join(",");
		var src =
			"https://www.openstreetmap.org/export/embed.html?bbox=" +
			encodeURIComponent(bbox) +
			"&layer=mapnik&marker=" +
			encodeURIComponent(latNum + "," + lonNum);
		$preview.empty().append(
			$("<iframe>", { src: src, title: i18n.mapTitle || "Map", loading: "lazy" })
		);
	};

	var $address = $("#event_location");
	if ($address.length && $address.closest(".blt-address-autocomplete").length) {
		var $list = $("#blt-address-suggestions");
		var searchTimer = null;
		var activeRequest = null;
		var activeIndex = -1;

		var clearCoords = function () {
			$("#event_latitude").val("");
			$("#event_longitude").val("");
			updateMapPreview("", "");
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
			updateMapPreview($item.attr("data-lat"), $item.attr("data-lon"));
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

	/* ----------------------------------------------------------------
	 * Ticket types
	 * ---------------------------------------------------------------- */
	var $ticketList = $("#blt-tickets-list");
	var ticketIndex = parseInt($ticketList.attr("data-next-index"), 10) || 0;

	var refreshTicketsEmptyState = function () {
		var hasTickets = $ticketList.children(".blt-ticket").length > 0;
		$("#blt-tickets-empty").toggle(!hasTickets);
		$("#blt-add-ticket-wrap").toggle(hasTickets);
	};

	$(document).on("click", ".blt-add-ticket", function () {
		var html = $("#tmpl-blt-ticket").html();
		if (!html) {
			return;
		}
		$ticketList.append(html.replace(/__i__/g, ticketIndex++));
		refreshTicketsEmptyState();
		$ticketList.children(".blt-ticket").last().find(".blt-ticket-name-input").trigger("focus").trigger("select");
	});

	$(document).on("click", ".blt-ticket-remove", function () {
		$(this).closest(".blt-ticket").remove();
		refreshTicketsEmptyState();
	});

	$(document).on("click", ".blt-ticket-toggle", function () {
		var $ticket = $(this).closest(".blt-ticket");
		var expanded = $ticket.toggleClass("is-expanded").hasClass("is-expanded");
		$(this)
			.attr("aria-expanded", expanded ? "true" : "false")
			.find(".dashicons")
			.toggleClass("dashicons-arrow-down-alt2", !expanded)
			.toggleClass("dashicons-arrow-up-alt2", expanded);
	});

	// Keep the header badge and price summary in sync with the price field.
	$(document).on("input change", ".blt-ticket-price-input", function () {
		var $ticket = $(this).closest(".blt-ticket");
		var price = parseFloat($(this).val());
		if (isNaN(price) || price < 0) {
			price = 0;
		}
		var isPaid = price > 0;

		$ticket
			.find(".blt-ticket-badge")
			.toggleClass("is-paid", isPaid)
			.toggleClass("is-free", !isPaid)
			.find(".dashicons")
			.toggleClass("dashicons-money-alt", isPaid)
			.toggleClass("dashicons-yes-alt", !isPaid);
		$ticket.find(".blt-ticket-badge-text").text(isPaid ? i18n.paid || "Paid" : i18n.free || "Free");
		$ticket
			.find(".blt-ticket-price-summary")
			.text((i18n.currencySymbol || "$") + price.toFixed(2));
	});

	// Role restriction: show role choices and the header lock only when on.
	$(document).on("change", ".blt-ticket-restrict input", function () {
		var $ticket = $(this).closest(".blt-ticket");
		$ticket.find(".blt-ticket-roles").toggle(this.checked);
		$ticket.find(".blt-ticket-lock").toggle(this.checked);
	});

	/* ----------------------------------------------------------------
	 * Registration configuration
	 * ---------------------------------------------------------------- */
	$("#blt-registration-open").on("change", function () {
		$("#blt-reg-config-fields").toggle(this.checked);
	});

	$("#blt-capacity-unlimited").on("change", function () {
		$("#blt-capacity-field").toggle(!this.checked);
		$("#blt-capacity-unlimited-help").toggle(this.checked);
	});

	$("#blt-group-discount-enabled").on("change", function () {
		$(".blt-group-discount-settings").toggle(this.checked);
	});

	/* ----------------------------------------------------------------
	 * Additional options: featured star fills in when enabled
	 * ---------------------------------------------------------------- */
	$(".blt-featured-toggle input[type=checkbox]").on("change", function () {
		$(this)
			.closest(".blt-toggle-row")
			.find(".blt-toggle-text > .dashicons")
			.toggleClass("dashicons-star-filled", this.checked)
			.toggleClass("dashicons-star-empty", !this.checked);
	});
});
