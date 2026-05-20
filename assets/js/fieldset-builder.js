/**
 * BLT Events - Fieldset Builder Admin JavaScript
 *
 * Handles drag-and-drop field ordering, adding/removing fields,
 * and AJAX save/delete of fieldsets.
 */
jQuery(document).ready(function ($) {
	"use strict";

	var fieldIndex = $(".blt-field-item").length;
	var consentIndex = $(".blt-consent-item").length;

	// --- Sortable fields ---
	if ($.fn.sortable) {
		$("#blt-fields-sortable").sortable({
			handle: ".blt-field-drag",
			placeholder: "blt-field-item ui-sortable-placeholder",
			tolerance: "pointer",
		});
	}

	// --- Toggle field settings ---
	$(document).on("click", ".blt-field-toggle", function () {
		$(this).closest(".blt-field-item").find(".blt-field-settings").slideToggle(200);
	});

	// --- Remove field ---
	$(document).on("click", ".blt-field-remove", function () {
		if (confirm("Remove this field?")) {
			$(this).closest(".blt-field-item").fadeOut(200, function () {
				$(this).remove();
			});
		}
	});

	// --- Add field ---
	$("#blt-add-field").on("click", function () {
		var html =
			'<div class="blt-field-item" data-index="' + fieldIndex + '">' +
			'  <div class="blt-field-header">' +
			'    <span class="blt-field-drag dashicons dashicons-move"></span>' +
			'    <span class="blt-field-label">New Field</span>' +
			'    <span class="blt-field-type">text</span>' +
			'    <button type="button" class="blt-field-toggle dashicons dashicons-arrow-down-alt2"></button>' +
			'    <button type="button" class="blt-field-remove dashicons dashicons-trash"></button>' +
			'  </div>' +
			'  <div class="blt-field-settings">' +
			'    <input type="hidden" name="fields[' + fieldIndex + '][key]" value="field_' + fieldIndex + '" />' +
			'    <label>Label: <input type="text" name="fields[' + fieldIndex + '][label]" value="New Field" /></label>' +
			'    <label>Type: <select name="fields[' + fieldIndex + '][type]">' +
			'      <option value="text">Text</option><option value="email">Email</option>' +
			'      <option value="tel">Tel</option><option value="url">Url</option>' +
			'      <option value="number">Number</option><option value="date">Date</option>' +
			'      <option value="select">Select</option><option value="textarea">Textarea</option>' +
			'      <option value="checkbox">Checkbox</option></select></label>' +
			'    <label>Width: <select name="fields[' + fieldIndex + '][width]">' +
			'      <option value="full">Full</option><option value="half">Half</option>' +
			'      <option value="third">Third</option></select></label>' +
			'    <label><input type="checkbox" name="fields[' + fieldIndex + '][required]" value="1" /> Required</label>' +
			'    <label>Placeholder: <input type="text" name="fields[' + fieldIndex + '][placeholder]" /></label>' +
			'    <label>Options (comma-separated): <input type="text" name="fields[' + fieldIndex + '][options_str]" /></label>' +
			'    <label><input type="checkbox" name="fields[' + fieldIndex + '][allow_other]" value="1" /> Allow "Other" option</label>' +
			'  </div>' +
			'</div>';

		$("#blt-fields-sortable").append(html);
		fieldIndex++;
	});

	// Update label text in header when label input changes
	$(document).on("input", ".blt-field-settings input[name$='[label]']", function () {
		$(this).closest(".blt-field-item").find(".blt-field-label").text($(this).val());
	});

	// Update type badge when type changes
	$(document).on("change", ".blt-field-settings select[name$='[type]']", function () {
		$(this).closest(".blt-field-item").find(".blt-field-type").text($(this).val());
	});

	// --- Add consent field ---
	$("#blt-add-consent").on("click", function () {
		var html =
			'<div class="blt-consent-item">' +
			'  <label>Key: <input type="text" name="consent[' + consentIndex + '][key]" value="consent_' + consentIndex + '" /></label>' +
			'  <label>Label (HTML): <input type="text" name="consent[' + consentIndex + '][label]" class="large-text" /></label>' +
			'  <label><input type="checkbox" name="consent[' + consentIndex + '][required]" value="1" checked /> Required</label>' +
			'  <button type="button" class="button button-link-delete blt-remove-consent">&times;</button>' +
			'</div>';

		$("#blt-consent-fields").append(html);
		consentIndex++;
	});

	// Remove consent field
	$(document).on("click", ".blt-remove-consent", function () {
		$(this).closest(".blt-consent-item").remove();
	});

	// --- Save fieldset via AJAX ---
	$("#blt-fieldset-form").on("submit", function (e) {
		e.preventDefault();

		var data = $(this).serialize();
		data += "&action=blt_save_fieldset&nonce=" + bltFieldsetData.nonce;

		$.ajax({
			url: bltFieldsetData.ajaxUrl,
			method: "POST",
			data: data,
			success: function (response) {
				if (response.success) {
					alert(response.data.message);
					if (!$('input[name="fieldset_id"]').val()) {
						window.location.href = window.location.href.split("?")[0] +
							"?post_type=event&page=blt-fieldsets&edit=" + response.data.id;
					}
				} else {
					alert("Error: " + response.data.message);
				}
			},
			error: function () {
				alert("Failed to save fieldset. Please try again.");
			},
		});
	});

	// --- Delete fieldset ---
	$(document).on("click", ".blt-delete-fieldset", function () {
		if (!confirm("Are you sure you want to delete this fieldset?")) return;

		var id = $(this).data("id");

		$.ajax({
			url: bltFieldsetData.ajaxUrl,
			method: "POST",
			data: {
				action: "blt_delete_fieldset",
				nonce: bltFieldsetData.nonce,
				fieldset_id: id,
			},
			success: function (response) {
				if (response.success) {
					window.location.reload();
				} else {
					alert("Error: " + response.data.message);
				}
			},
		});
	});
});
