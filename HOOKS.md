# BLT Events hooks

Every action and filter the plugin exposes, grouped by area. Parameters are listed in order. Filters return the first parameter.

## Lifecycle

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_events_loaded` | action | | The plugin has booted (end of `plugins_loaded`) |
| `blt_events_installed` | action | `$from_version, $is_upgrade` | Install/upgrade routine finished for the current site |
| `blt_events_default_options` | filter | `$defaults, $is_upgrade` | Options seeded on install/upgrade |
| `blt_events_default_fieldset_fields` | filter | `$fields` | Fields of the default fieldset seeded on a fresh install |
| `blt_events_default_consent_fields` | filter | `$fields` | Consent checkboxes seeded on a fresh install |
| `blt_events_role_caps` | filter | `$map` | Capabilities granted per role on install/upgrade |
| `blt_events_post_type_args` | filter | `$args` | `register_post_type( 'event' )` arguments |
| `blt_events_taxonomy_args` | filter | `$args` | `register_taxonomy( 'event_category' )` arguments |
| `blt_events_event_saved` | action | `$post_id, $post` | Event meta saved from the editor |
| `blt_events_admin_hooks` | filter | `$hooks` | Admin screen hook suffixes that load the plugin's admin assets |
| `blt_events_enqueue_assets` | filter | `false` | Force-load front-end assets on the current request |

## Registrations

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_registration_created` | action | `$registration_id, $result` | A registration was stored |
| `blt_registration_needs_review` | action | `$registration_id, $review, $result` | A captured payment tripped a guard and was stored pending |
| `blt_registration_status_changed` | action | `$registration_id, $status, $old_status` | Any status change |
| `blt_registration_confirmed` | action | `$registration_id` | Status became `confirmed` |
| `blt_registration_cancelled` | action | `$registration_id` | Status became `cancelled` |
| `blt_registration_refunded` | action | `$registration_id` | Status became `refunded` |
| `blt_registration_partially_refunded` | action | `$registration_id, $refunded_amount, $order` | FluentCart partial refund |
| `blt_events_payment_orphaned` | action | `$provider, $payment_id, $event_id, WP_Error $error` | A completed payment produced no registration |
| `blt_events_registration_data` | filter | `$data, $event_id, $payment` | Submitted data before validation |
| `blt_events_new_registration_status` | filter | `$status, $event_id, $payment` | Status a new registration is stored with |
| `blt_events_registration_insert_data` | filter | `$row, $validated, $event_id` | Row about to be inserted |
| `blt_events_attendees_data` | filter | `$attendees, $data, $ticket_data, $validated` | Attendee rows about to be inserted |
| `blt_events_order_pricing` | filter | `$pricing, $event_id, $ticket_data, $data` | Computed subtotal/discount/total |
| `blt_events_max_ticket_quantity` | filter | `50, $ticket, $event_id` | Max quantity of one ticket per registration |
| `blt_events_registration_statuses` | filter | `$statuses` | Slug => label of all statuses |
| `blt_events_seat_holding_statuses` | filter | `['pending','confirmed']` | Statuses that occupy capacity |
| `blt_events_registration_success_message` | filter | `$message, $status` | Message shown after a submission |
| `blt_events_registration_rate_limit` | filter | `10, $bucket` | Requests per IP per 10 minutes (`register` or `coupon`); 0 disables |
| `blt_events_registration_email_rate_limit` | filter | `5` | Submissions per email per 10 minutes |
| `blt_events_trusted_ip_headers` | filter | `$headers` | `$_SERVER` keys trusted for the client IP |

## Fieldsets and validation

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_events_field_types` | filter | `$types` | Registered field types (add your own with `render`/`sanitize` callables) |
| `blt_events_fieldset_fields` | filter | `$fields, $fieldset` | Fields read from a fieldset |
| `blt_events_fieldset_consent_fields` | filter | `$fields, $fieldset` | Consent checkboxes read from a fieldset |
| `blt_events_fieldset_presets` | filter | `$presets` | Presets offered when creating a fieldset |
| `blt_events_render_field` | filter | `$html, $field, $value, $prefix` | Rendered HTML of one field |
| `blt_events_prefill_value` | filter | `$value, $field, $user` | Prefilled value for a field |
| `blt_events_validation_errors` | filter | `$errors, $clean, $posted, $fieldset` | Validation messages before returning |
| `blt_events_attendee_fields` | filter | `$fields, $event_id` | Fields collected per additional attendee (default: first name, last name, email, phone; `first_name` + `last_name` are joined into the attendee name) |
| `blt_events_countries` | filter | `$countries` | Country list for the country field |

## Front end

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_events_template_paths` | filter | `$paths, $template` | Directories searched for a template |
| `blt_events_locate_template` | filter | `$located, $template` | Located template file |
| `blt_events_template_args` | filter | `$args, $template` | Data passed to a template |
| `blt_events_before_template` / `blt_events_after_template` | action | `$template, $args` | Around every template include |
| `blt_events_render_single` | filter | `true, $event` | Whether to render the single event layout |
| `blt_events_single_view_data` | filter | `$data, $event` | All data for the single event templates |
| `blt_events_single_show_title` / `_show_featured` / `_show_back` / `_show_calendar_links` | filter | `$show, $event_id` | Element toggles on the event page |
| `blt_events_cta_label` | filter | `$label, $event_id, $range` | CTA button text |
| `blt_events_can_see_online_url` | filter | `$can_see, $event_id` | Whether the visitor may see the join link |
| `blt_events_map_src` | filter | `$src, $event_id, $provider` | Map iframe URL |
| `blt_events_single_before_main` / `_after_main` | action | `$event_id` | Top / bottom of the main column on the event page |
| `blt_events_single_before_sidebar` / `blt_events_single_sidebar` | action | `$event_id` | Inside the event card: before the date / above the register button (the speakers render on `blt_events_single_sidebar`) |
| `blt_events_presenters` | filter | `$presenters, $event_id` | Speakers (presenters) shown in the event card |
| `blt_events_sponsors` | filter | `$sponsors, $event_id` | Sponsor logos on the event page (`id`, `url`, `full`, `alt`); runs even when the section is off |
| `blt_events_calendar_query_args` | filter | `$args, $view, $atts` | `WP_Query` args of a calendar view |
| `blt_events_calendar_views` | filter | `$views` | View switcher labels |
| `blt_events_calendar_html` | filter | `$html, $view, $atts` | Rendered calendar/listing |
| `blt_events_list_date_format` | filter | `'F j'` | Day format in list rows |
| `blt_events_list_time_separator` | filter | `' @ '` | Separator between date and time |
| `blt_events_list_datetime_label` | filter | `$label, $when, $event_id` | Date/time line of a list row |
| `blt_events_price_label` | filter | `$label, $range, $event_id` | Price text on cards |
| `blt_events_event_date_label` / `blt_events_event_time_label` | filter | `$label, $event_id` | Formatted date/time labels |
| `blt_events_format_price` | filter | `$string, $amount, $include_total` | Formatted price |
| `blt_events_registration_html` | filter | `$html, $event` | Complete registration block |
| `blt_events_registration_form_args` | filter | `$args, $event_id` | Data for the form template |
| `blt_events_registration_ticket_rows` | filter | `$rows, $event_id` | Tickets listed on the Registration step, each with a `state` (`available`, `members`, `ended`, `upcoming`); unset rows to hide them |
| `blt_events_member_login_url` | filter | `$url, $return, $event_id` | Where "Log in for Member Rates" sends a logged-out visitor |
| `blt_events_registration_summary` | filter | `$summary, $event` | Event facts in the checkout's "Event summary" card |
| `blt_events_registration_help_email` | filter | `$email, $event_id` | Address in the checkout's "Need help?" box (`''` hides it) |
| `blt_events_registration_closed_message` | filter | `$message, $reason, $event_id` | Text when registration is closed (`not_open`, `cutoff`, `sold_out`, `no_tickets`, `syncing`) |
| `blt_events_before_registration_form` / `_after_registration_form` | action | `$event_id, $provider` | Around the form |
| `blt_events_style_tokens` | filter | `$rules, $mode` | `--blt-e-*` overrides printed inline |
| `blt_events_json_ld` | filter | `$data, $event` | schema.org Event data (return `[]` to skip) |
| `blt_events_archive_shortcode_atts` | filter | `$atts` | Shortcode attributes of the classic archive template |
| `blt_events_archive_before` / `_after` | action | | Around the archive listing |
| `blt_events_events_page_content` | filter | `$content` | Content of the auto-created Events page |
| `blt_events_ics_content` | filter | `$ics, $event` | Generated .ics text |
| `blt_events_google_calendar_url` | filter | `$url, $event` | Google Calendar link |

## Emails and reminders

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_events_email_templates` | filter | `$templates` | Email catalogue (add types; each gets a settings card) |
| `blt_events_email_placeholders` | filter | `$placeholders` | Placeholder reference shown in settings |
| `blt_events_email_replacements` | filter | `$replacements, $reg, $event, $type` | Placeholder values |
| `blt_events_should_send_email` | filter | `true, $type, $reg` | Suppress an email |
| `blt_events_email_recipient` | filter | `$to, $type, $reg` | Recipient(s) |
| `blt_events_email_subject` | filter | `$subject, $type, $reg` | Subject |
| `blt_events_email_body` | filter | `$body, $type, $reg` | Body before wrapping |
| `blt_events_email_html` | filter | `$html, $type, $reg` | Final HTML |
| `blt_events_email_headers` | filter | `$headers, $type, $reg` | Headers |
| `blt_events_email_attachments` | filter | `$attachments, $type, $reg` | Attachments |
| `blt_events_email_sent` | action | `$sent, $type, $reg, $subject` | After `wp_mail()` |
| `blt_events_reminder_windows` | filter | `$windows` | Reminder type => seconds before start |
| `blt_events_send_reminder_to` | filter | `true, $reg, $type` | Skip a registration for a reminder |
| `blt_events_reminders_sent` | action | `$event_id, $type, $sent` | After a reminder run for one event |

## Payments and integrations

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_events_payment_providers` | filter | `$providers` | Provider registry |
| `blt_events_event_payment_provider` | filter | `$slug, $event_id` | Provider an event checks out through |
| `blt_events_stripe_webhook` | action | `$event` | Every verified Stripe webhook |
| `blt_events_fluentcrm_contact_status` | filter | `'pending', $reg` | FluentCRM subscription status |
| `blt_events_fluentcrm_should_sync` | filter | `true, $reg` | Whether to sync a registration to FluentCRM |

## Admin and REST

| Hook | Type | Parameters | Fires when |
|---|---|---|---|
| `blt_events_settings_tabs` | filter | `$tabs` | Settings tabs |
| `blt_events_render_settings_tab` | action | `$tab` | Render a tab the plugin does not know |
| `blt_events_register_settings` | action | | After core settings are registered |
| `blt_events_settings_payments_after` / `_emails_after` / `_integrations_after` | action | | End of those tabs, inside the form |
| `blt_events_setup_checks` | filter | `$checks` | Setup checklist |
| `blt_events_shortcode_reference` | filter | `$shortcodes` | Reference table on the Shortcodes tab |
| `blt_events_registration_bulk_actions` | filter | `$actions` | Bulk actions on the Registrations screen |
| `blt_events_registrations_per_page` | filter | `20` | Rows per page |
| `blt_events_csv_columns` / `blt_events_csv_row` | filter | `$columns, $event_id` / `$row, $reg, $event_id` | Registrations CSV |
| `blt_events_attendees_csv_columns` / `blt_events_attendees_csv_row` | filter | `$columns, $event_id` / `$row, $att, $event_id` | Attendees CSV |
| `blt_events_rest_meta_keys` | filter | `$keys` | Event meta exposed to REST |
| `blt_events_rest_summary` | filter | `$data, $event_id` | Computed `blt_event` REST field |

## JavaScript events

The registration form triggers `blt:registered` on the form element after a successful registration (payload: server response).
