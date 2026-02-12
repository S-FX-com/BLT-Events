/**
 * CMT Events - Fieldset Builder Admin JavaScript
 *
 * Handles drag-and-drop field ordering, adding/removing fields,
 * and AJAX save/delete of fieldsets.
 */
jQuery(document).ready(function ($) {
	"use strict";

	var fieldIndex = $(".cmt-field-item").length;
	var consentIndex = $(".cmt-consent-item").length;

	// --- Sortable fields ---
	if ($.fn.sortable) {
		$("#cmt-fields-sortable").sortable({
			handle: ".cmt-field-drag",
			placeholder: "cmt-field-item ui-sortable-placeholder",
			tolerance: "pointer",
		});
	}

	// --- Toggle field settings ---
	$(document).on("click", ".cmt-field-toggle", function () {
		$(this).closest(".cmt-field-item").find(".cmt-field-settings").slideToggle(200);
	});

	// --- Remove field ---
	$(document).on("click", ".cmt-field-remove", function () {
		if (confirm("Remove this field?")) {
			$(this).closest(".cmt-field-item").fadeOut(200, function () {
				$(this).remove();
			});
		}
	});

	// --- Add field ---
	$("#cmt-add-field").on("click", function () {
		var html =
			'<div class="cmt-field-item" data-index="' + fieldIndex + '">' +
			'  <div class="cmt-field-header">' +
			'    <span class="cmt-field-drag dashicons dashicons-move"></span>' +
			'    <span class="cmt-field-label">New Field</span>' +
			'    <span class="cmt-field-type">text</span>' +
			'    <button type="button" class="cmt-field-toggle dashicons dashicons-arrow-down-alt2"></button>' +
			'    <button type="button" class="cmt-field-remove dashicons dashicons-trash"></button>' +
			'  </div>' +
			'  <div class="cmt-field-settings">' +
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

		$("#cmt-fields-sortable").append(html);
		fieldIndex++;
	});

	// Update label text in header when label input changes
	$(document).on("input", ".cmt-field-settings input[name$='[label]']", function () {
		$(this).closest(".cmt-field-item").find(".cmt-field-label").text($(this).val());
	});

	// Update type badge when type changes
	$(document).on("change", ".cmt-field-settings select[name$='[type]']", function () {
		$(this).closest(".cmt-field-item").find(".cmt-field-type").text($(this).val());
	});

	// --- Add consent field ---
	$("#cmt-add-consent").on("click", function () {
		var html =
			'<div class="cmt-consent-item">' +
			'  <label>Key: <input type="text" name="consent[' + consentIndex + '][key]" value="consent_' + consentIndex + '" /></label>' +
			'  <label>Label (HTML): <input type="text" name="consent[' + consentIndex + '][label]" class="large-text" /></label>' +
			'  <label><input type="checkbox" name="consent[' + consentIndex + '][required]" value="1" checked /> Required</label>' +
			'  <button type="button" class="button button-link-delete cmt-remove-consent">&times;</button>' +
			'</div>';

		$("#cmt-consent-fields").append(html);
		consentIndex++;
	});

	// Remove consent field
	$(document).on("click", ".cmt-remove-consent", function () {
		$(this).closest(".cmt-consent-item").remove();
	});

	// --- Save fieldset via AJAX ---
	$("#cmt-fieldset-form").on("submit", function (e) {
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
	$(document).on("click", ".cmt-delete-fieldset", function () {
		if (!confirm("Are you sure you want to delete this fieldset?")) return;

		var id = $(this).data("id");

		$.ajax({
			url: cmtFieldsetData.ajaxUrl,
			method: "POST",
			data: {
				action: "cmt_delete_fieldset",
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
