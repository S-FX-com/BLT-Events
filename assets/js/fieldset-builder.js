/**
 * BLT Events - Fieldset Builder Admin JavaScript
 *
 * Handles drag-and-drop field ordering, adding/removing fields, the
 * per-type settings panels, conditional-logic source lists, presets, and
 * AJAX save/delete/duplicate of fieldsets. All row markup comes from the
 * PHP-rendered <script type="text/html"> templates, so the two never drift.
 */
jQuery(document).ready(function ($) {
	"use strict";

	var data = window.bltFieldsetData || {};
	var i18n = data.i18n || {};
	var typesWithOptions = data.typesWithOptions || [];
	var fieldIndex = $(".blt-field-item").length;
	var consentIndex = $(".blt-consent-item").length;
	var $list = $("#blt-fields-sortable");

	function fieldTemplate(index) {
		return $("#tmpl-blt-field-item").html().replace(/__i__/g, String(index));
	}

	function consentTemplate(index) {
		return $("#tmpl-blt-consent-item").html().replace(/__i__/g, String(index));
	}

	function slugify(text) {
		return String(text || "")
			.toLowerCase()
			.normalize("NFD")
			.replace(/[̀-ͯ]/g, "")
			.replace(/[^a-z0-9]+/g, "_")
			.replace(/^_+|_+$/g, "")
			.slice(0, 40);
	}

	// --- Sortable fields ---
	if ($.fn.sortable) {
		$list.sortable({
			handle: ".blt-field-drag",
			placeholder: "blt-field-item ui-sortable-placeholder",
			tolerance: "pointer",
			update: refreshConditionSources
		});
	}

	// --- Per-type panels ---
	function syncTypePanels($item) {
		var type = $item.find('[data-blt-fs="type"]').val();
		var hasOptions = typesWithOptions.indexOf(type) !== -1;
		var isHtml = type === "html";
		var isHidden = type === "hidden";
		var isNumber = type === "number";
		var textLike = ["text", "email", "tel", "url", "textarea"].indexOf(type) !== -1;

		$item.attr("data-type", type);
		$item.find(".blt-fs-options").toggle(hasOptions);
		$item.find(".blt-fs-content").toggle(isHtml);
		$item.find(".blt-fs-placeholder").toggle(!isHtml && !isHidden && !hasOptions && type !== "checkbox" && type !== "country");
		$item.find(".blt-fs-default").toggle(!isHtml);
		$item.find(".blt-fs-description").toggle(!isHtml);
		$item.find(".blt-fs-required").toggle(!isHtml && !isHidden);
		$item.find(".blt-fs-validation").toggle(!isHtml && !isHidden && type !== "checkbox" && type !== "date");
		$item.find(".blt-fs-len").toggle(textLike);
		$item.find(".blt-fs-pattern").toggle(textLike && type !== "textarea");
		$item.find(".blt-fs-num").toggle(isNumber);
		$item.find(".blt-map-group").toggle(!isHtml);
		$item.find(".blt-field-type").text($item.find('[data-blt-fs="type"] option:selected').text());
	}

	function syncHeader($item) {
		var label = $item.find('[data-blt-fs="label"]').val();
		var key = $item.find('[data-blt-fs="key"]').val();
		var required = $item.find('[data-blt-fs="required"]').prop("checked");

		$item.find(".blt-fitem-label").text(label || i18n.newField || "Field");
		$item.find(".blt-fitem-key").text(key);

		var $star = $item.find(".blt-fitem-required");
		if (required && !$star.length) {
			$('<span class="blt-fitem-required">*</span>').insertAfter($item.find(".blt-field-type"));
		} else if (!required) {
			$star.remove();
		}
	}

	// Conditional-logic "when field" lists: every other field's key.
	function refreshConditionSources() {
		var entries = [];
		$list.find(".blt-field-item").each(function () {
			var $it = $(this);
			var type = $it.find('[data-blt-fs="type"]').val();
			if (type === "html") {
				return;
			}
			entries.push({
				key: $it.find('[data-blt-fs="key"]').val(),
				label: $it.find('[data-blt-fs="label"]').val()
			});
		});

		$list.find(".blt-field-item").each(function () {
			var $it = $(this);
			var ownKey = $it.find('[data-blt-fs="key"]').val();
			var $select = $it.find('[data-blt-fs="cond-field"]');
			var current = $select.val() || $select.data("current") || "";

			$select.find("option:not(:first)").remove();
			entries.forEach(function (entry) {
				if (!entry.key || entry.key === ownKey) {
					return;
				}
				$select.append(
					$("<option>").val(entry.key).text((entry.label || entry.key) + " (" + entry.key + ")")
				);
			});
			$select.val(current);
			if ($select.val() !== current) {
				$select.val("");
			}
			syncConditionRow($it);
		});
	}

	function syncConditionRow($item) {
		var field = $item.find('[data-blt-fs="cond-field"]').val();
		var op = $item.find('[data-blt-fs="cond-op"]').val();
		var needsValue = field && op !== "empty" && op !== "not_empty";

		$item.find('[data-blt-fs="cond-op"]').closest("label").toggle(!!field);
		$item.find(".blt-fs-cond-value").toggle(!!needsValue);
	}

	$list.find(".blt-field-item").each(function () {
		syncTypePanels($(this));
	});
	refreshConditionSources();

	// --- Toggle field settings ---
	$(document).on("click", ".blt-field-toggle", function () {
		var $item = $(this).closest(".blt-field-item");
		$item.toggleClass("is-expanded");
		$item.find(".blt-field-settings").slideToggle(200);
	});

	// --- Remove field ---
	$(document).on("click", ".blt-field-remove", function () {
		if (window.confirm(i18n.removeField || "Remove this field?")) {
			$(this).closest(".blt-field-item").fadeOut(200, function () {
				$(this).remove();
				refreshConditionSources();
			});
		}
	});

	// --- Add field ---
	function addField(values) {
		var $item = $(fieldTemplate(fieldIndex));
		fieldIndex++;
		$list.append($item);

		if (values) {
			fillField($item, values);
		}

		syncTypePanels($item);
		syncHeader($item);
		refreshConditionSources();
		return $item;
	}

	function fillField($item, v) {
		$item.find('[data-blt-fs="label"]').val(v.label || "");
		$item.find('[data-blt-fs="key"]').val(v.key || slugify(v.label));
		$item.find('[data-blt-fs="type"]').val(v.type || "text");
		$item.find('[data-blt-fs="width"]').val(v.width || "full");
		$item.find('[data-blt-fs="required"]').prop("checked", !!v.required);
		$item.find('[data-blt-fs="placeholder"]').val(v.placeholder || "");
		$item.find('[data-blt-fs="default"]').val(v["default"] || "");
		$item.find('[data-blt-fs="description"]').val(v.description || "");
		$item.find('[data-blt-fs="options_str"]').val((v.options || []).join(", "));
		$item.find('[data-blt-fs="allow_other"]').prop("checked", !!v.allow_other);
		$item.find('[data-blt-fs="content"]').val(v.content || "");
		$item.find('input[name$="[map_user]"]').val(v.map_user || "");
		$item.find('input[name$="[map_acf]"]').val(v.map_acf || "");
		$item.find('input[name$="[map_fluentcrm]"]').val(v.map_fluentcrm || "");
	}

	$("#blt-add-field").on("click", function () {
		var $item = addField({ label: i18n.newField || "New Field" });
		$item.find('[data-blt-fs="label"]').trigger("focus").select();
	});

	// Header + panels follow the inputs.
	$(document).on("input", '.blt-field-settings [data-blt-fs="label"]', function () {
		var $item = $(this).closest(".blt-field-item");
		var $key = $item.find('[data-blt-fs="key"]');
		if (!$key.data("touched")) {
			$key.val(slugify($(this).val()));
		}
		syncHeader($item);
		refreshConditionSources();
	});

	$(document).on("input", '.blt-field-settings [data-blt-fs="key"]', function () {
		$(this).data("touched", true).val(slugify($(this).val()));
		syncHeader($(this).closest(".blt-field-item"));
		refreshConditionSources();
	});

	$(document).on("change", '.blt-field-settings [data-blt-fs="type"]', function () {
		syncTypePanels($(this).closest(".blt-field-item"));
		refreshConditionSources();
	});

	$(document).on("change", '.blt-field-settings [data-blt-fs="required"]', function () {
		syncHeader($(this).closest(".blt-field-item"));
	});

	$(document).on("change", '.blt-field-settings [data-blt-fs="cond-field"], .blt-field-settings [data-blt-fs="cond-op"]', function () {
		syncConditionRow($(this).closest(".blt-field-item"));
	});

	// --- Presets (new fieldset only) ---
	function applyPreset(slug, confirmFirst) {
		var preset = (data.presets || {})[slug];
		if (!preset) {
			return;
		}
		if (confirmFirst && $list.find(".blt-field-item").length && !window.confirm(i18n.applyPreset || "Replace the current fields with this preset?")) {
			return;
		}

		$list.empty();
		$("#blt-consent-fields").empty();

		(preset.fields || []).forEach(function (field) {
			addField(field);
		});
		(preset.consent_fields || []).forEach(function (cf) {
			var $c = $(consentTemplate(consentIndex));
			consentIndex++;
			$c.find('input[name$="[key]"]').val(cf.key || "");
			$c.find('input[name$="[label]"]').val(cf.label || "");
			$c.find('input[name$="[required]"]').prop("checked", !!cf.required);
			$("#blt-consent-fields").append($c);
		});

		$(".blt-preset-cards .blt-select-card").removeClass("is-selected")
			.filter(function () { return $(this).find("input").val() === slug; })
			.addClass("is-selected");
	}

	var $presetRadios = $("[data-blt-preset]");
	if ($presetRadios.length) {
		$presetRadios.on("change", function () {
			applyPreset($(this).val(), true);
		});
		applyPreset($presetRadios.filter(":checked").val(), false);
	}

	// --- Consent fields ---
	$("#blt-add-consent").on("click", function () {
		$("#blt-consent-fields").append(consentTemplate(consentIndex));
		consentIndex++;
	});

	$(document).on("click", ".blt-remove-consent", function () {
		$(this).closest(".blt-consent-item").remove();
	});

	// --- Save fieldset via AJAX ---
	$("#blt-fieldset-form").on("submit", function (e) {
		e.preventDefault();

		var $btn = $("#blt-save-fieldset");
		var payload = $(this).serialize();
		payload += "&action=blt_save_fieldset&nonce=" + encodeURIComponent(data.nonce);

		$btn.prop("disabled", true).text(i18n.saving || "Saving…");

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: payload,
			success: function (response) {
				if (response.success) {
					if (!$('input[name="fieldset_id"]').val()) {
						window.location.href = window.location.href.split("?")[0] +
							"?post_type=event&page=blt-fieldsets&edit=" + response.data.id + "&saved=1";
						return;
					}
					window.location.reload();
				} else {
					window.alert((i18n.error || "Error:") + " " + response.data.message);
					$btn.prop("disabled", false).text(i18n.save || "Save Fieldset");
				}
			},
			error: function () {
				window.alert(i18n.saveFailed || "Failed to save fieldset. Please try again.");
				$btn.prop("disabled", false).text(i18n.save || "Save Fieldset");
			}
		});
	});

	// --- Delete fieldset ---
	$(document).on("click", ".blt-delete-fieldset", function () {
		if (!window.confirm(i18n.deleteConfirm || "Are you sure you want to delete this fieldset?")) {
			return;
		}

		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "blt_delete_fieldset",
				nonce: data.nonce,
				fieldset_id: $(this).data("id")
			},
			success: function (response) {
				if (response.success) {
					window.location.href = window.location.href.split("?")[0] + "?post_type=event&page=blt-fieldsets";
				} else {
					window.alert((i18n.error || "Error:") + " " + response.data.message);
				}
			}
		});
	});

	// --- Duplicate fieldset ---
	$(document).on("click", ".blt-duplicate-fieldset", function () {
		$.ajax({
			url: data.ajaxUrl,
			method: "POST",
			data: {
				action: "blt_duplicate_fieldset",
				nonce: data.nonce,
				fieldset_id: $(this).data("id")
			},
			success: function (response) {
				if (response.success) {
					window.location.href = window.location.href.split("?")[0] +
						"?post_type=event&page=blt-fieldsets&edit=" + response.data.id;
				} else {
					window.alert((i18n.error || "Error:") + " " + response.data.message);
				}
			}
		});
	});
});
