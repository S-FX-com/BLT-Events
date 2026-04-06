/**
 * ZymEvents - Fieldset Builder Admin JavaScript
 *
 * Handles drag-and-drop field ordering, adding/removing fields,
 * and AJAX save/delete of fieldsets.
 */
jQuery(document).ready(function ($) {
	"use strict";

	var fieldIndex = $(".zymevents-field-item").length;
	var consentIndex = $(".zymevents-consent-item").length;

	// --- Sortable fields ---
	if ($.fn.sortable) {
		$("#zymevents-fields-sortable").sortable({
			handle: ".zymevents-field-drag",
			placeholder: "zymevents-field-item ui-sortable-placeholder",
			tolerance: "pointer",
		});
	}

	// --- Toggle field settings ---
	$(document).on("click", ".zymevents-field-toggle", function () {
		$(this).closest(".zymevents-field-item").find(".zymevents-field-settings").slideToggle(200);
	});

	// --- Remove field ---
	$(document).on("click", ".zymevents-field-remove", function () {
		if (confirm("Remove this field?")) {
			$(this).closest(".zymevents-field-item").fadeOut(200, function () {
				$(this).remove();
			});
		}
	});

	// --- Add field ---
	$("#zymevents-add-field").on("click", function () {
		var html =
			'<div class="zymevents-field-item" data-index="' + fieldIndex + '">' +
			'  <div class="zymevents-field-header">' +
			'    <span class="zymevents-field-drag dashicons dashicons-move"></span>' +
			'    <span class="zymevents-field-label">New Field</span>' +
			'    <span class="zymevents-field-type">text</span>' +
			'    <button type="button" class="zymevents-field-toggle dashicons dashicons-arrow-down-alt2"></button>' +
			'    <button type="button" class="zymevents-field-remove dashicons dashicons-trash"></button>' +
			'  </div>' +
			'  <div class="zymevents-field-settings">' +
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

		$("#zymevents-fields-sortable").append(html);
		fieldIndex++;
	});

	// Update label text in header when label input changes
	$(document).on("input", ".zymevents-field-settings input[name$='[label]']", function () {
		$(this).closest(".zymevents-field-item").find(".zymevents-field-label").text($(this).val());
	});

	// Update type badge when type changes
	$(document).on("change", ".zymevents-field-settings select[name$='[type]']", function () {
		$(this).closest(".zymevents-field-item").find(".zymevents-field-type").text($(this).val());
	});

	// --- Add consent field ---
	$("#zymevents-add-consent").on("click", function () {
		var html =
			'<div class="zymevents-consent-item">' +
			'  <label>Key: <input type="text" name="consent[' + consentIndex + '][key]" value="consent_' + consentIndex + '" /></label>' +
			'  <label>Label (HTML): <input type="text" name="consent[' + consentIndex + '][label]" class="large-text" /></label>' +
			'  <label><input type="checkbox" name="consent[' + consentIndex + '][required]" value="1" checked /> Required</label>' +
			'  <button type="button" class="button button-link-delete cmt-remove-consent">&times;</button>' +
			'</div>';

		$("#zymevents-consent-fields").append(html);
		consentIndex++;
	});

	// Remove consent field
	$(document).on("click", ".zymevents-remove-consent", function () {
		$(this).closest(".zymevents-consent-item").remove();
	});

	// --- Save fieldset via AJAX ---
	$("#zymevents-fieldset-form").on("submit", function (e) {
		e.preventDefault();

		var data = $(this).serialize();
		data += "&action=cmt_save_fieldset&nonce=" + cmtFieldsetData.nonce;

		$.ajax({
			url: cmtFieldsetData.ajaxUrl,
			method: "POST",
			data: data,
			success: function (response) {
				if (response.success) {
					alert(response.data.message);
					if (!$('input[name="fieldset_id"]').val()) {
						window.location.href = window.location.href.split("?")[0] +
							"?post_type=event&page=cmt-fieldsets&edit=" + response.data.id;
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
	$(document).on("click", ".zymevents-delete-fieldset", function () {
		if (!confirm("Are you sure you want to delete this fieldset?")) return;

		var id = $(this).data("id");

		$.ajax({
			url: cmtFieldsetData.ajaxUrl,
			method: "POST",
			data: {
				action: "zymevents_delete_fieldset",
				nonce: cmtFieldsetData.nonce,
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
