# Changelog

All notable changes to BLT Events. Versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- **Event editor layout.** The main column now reads Event Details, Event Description, Event Type, Registration Configuration, Ticket Types, then everything else. The content editor sits in its own **Event Description** card. Each user's saved drag-and-drop box order is cleared once so the new order shows; boxes can be rearranged again afterwards.
- **No Excerpt box.** Events no longer support excerpts. Excerpts saved on existing events are still stored and still shown in listings and structured data, but can no longer be edited.
- **Registration is a three-step checkout** (Stripe and free events): Registration (ticket table with per-row and running totals), Attendee details (a card per attendee with the ticket type, price and a "Remove attendee" action, beside an event summary), and Review & payment (ticket and attendee summaries with edit links, then the card form). When the order total is zero the payment step is skipped and the order completes from Attendee details. Events without ticket types open straight on the details step.
- The Stripe card is collected as separate cardholder name, card number, expiry and CVC fields. Theme overrides that still print `#blt-card-element` keep the single combined field.
- Tickets whose sale window has closed or not yet opened are listed disabled ("Sales ended", "On sale …") instead of being hidden. Remove them with `blt_events_registration_ticket_rows`.
- Additional attendees are asked for first and last name (joined into the attendee name) instead of a single full-name field.
- The checkout summary includes the event's group discount, so the total shown matches what is charged.
- `templates/registration/form.php` and `attendee.php` were rewritten. Theme overrides of them need updating to the new markup.

- **Event page redesigned** to the new layout: the featured image at 16:9 with rounded corners and a "Back to events" button over it; category chips, title, description (715px measure), an **Agenda** accordion (one item per session, time as the title, first item open), **Sponsors** and a **Location** map in the main column, with a divider above each section; and one sticky event card beside them, lifted over the hero image, holding the date box, event facts (time, venue, online attendance and join link, price, add-to-calendar links), the speakers and the register button. Below 992px the card moves up under the title.
- The card only overlaps the hero when a featured image is shown (the old sidebar overlapped even without one).
- The register button in the card points at the form itself when the form is placed inside the description.
- Presenters are titled "Speakers" on the event page and show photo, name and role; bios are no longer printed there.
- The four `blt_events_single_*` actions still fire; `blt_events_single_before_sidebar` and `blt_events_single_sidebar` now run inside the event card.
- New design tokens for the event page (`--blt-e-space-xs/s/m`, `--blt-e-content-gap`, `--blt-e-grid-gap`, `--blt-e-section-space-m`, `--blt-e-text-s/m/l`, `--blt-e-radius-xs`, `--blt-e-neutral*`, `--blt-e-divider`, `--blt-e-chip-*`, `--blt-e-prose-width`, `--blt-e-sponsor-*`). Skeleton mode maps them to the matching Automatic.css variables, and Settings > Appearance > Corner radius now reaches the event card, hero and buttons.
- Theme overrides of `single-event.php` or of the `single/` parts need reviewing. Every part name still resolves. The time, price, calendar links and venue now live in the new `single/info.php` facts list; `address.php` is the main-column Location section (shown only with a map), `virtual.php` renders inside the facts list, and `datebox.php` / `cta.php` no longer print their own cards. A theme copy of the old `single-event.php` keeps working: without the new layout's `card_info` flag, `datebox.php` still prints the time and calendar links, `cta.php` the price and `address.php` the address card. A theme that overrides only the old `datebox.php` or `cta.php` shows those facts twice (in its part and in the facts list) until the override is updated. New parts: `single/info.php`, `single/excerpt.php`, `single/sponsors.php`.
- The event card sticks only when it fits in the window, so a tall card never hides its register button. Focus outlines on the event page are solid accent outlines (visible in forced-colours mode), and the register button uses the deeper accent shade so white text stays above 4.5:1.

### Added

- **Sponsors.** A new Sponsors box in the event editor: pick logos from the media library, drag to reorder, optionally link each to the sponsor's site. They show as square tiles under the agenda; a logo without a link opens larger in a lightbox. Filter with `blt_events_sponsors`.
- **Log in for Member Rates.** Logged-out visitors see a prompt, and locked "Members only" rows, when a role-restricted ticket type is on sale; logging in returns them to the form. SureCart and FluentCart checkouts show the prompt too. Filter the link with `blt_events_member_login_url`.
- New templates `registration/summary.php` and `registration/member-login.php`; new filters `blt_events_registration_ticket_rows`, `blt_events_member_login_url`, `blt_events_registration_summary` and `blt_events_registration_help_email`.
- The "Need help?" box in the checkout shows the Reply-To (or From) address from Settings > Emails, and is hidden when neither is set.

### Fixed

- **Ticket types, agenda, speakers, sponsors, event days and group discounts could be corrupted on save.** `update_post_meta()` strips backslashes from what it is given, so any value whose JSON contained one (a quote in a ticket name, an accented character, a line break in a description) was stored as broken JSON and read back as empty: `Café "VIP"` lost its tickets. JSON meta is now slashed before saving (new `BLT_Events_Helpers::update_json_meta()`), and so are the meeting room details and the values copied by the event migration. Events already saved with broken JSON can't be repaired automatically: re-enter the affected ticket types, agenda, speakers or sponsors and save the event again.
- **The online join link was public.** The "Add to Google Calendar" link, the public .ics download (event page and REST route), the `blt_event` REST summary and the calendar shortcode put the join URL in the event location, so anyone could read it. They now say "Online" (online events) or the venue and address followed by "Online" (hybrid events). The link still reaches registrants: the confirmation email's `{event_location}` and attached .ics include it for confirmed registrations only, the same rule as `{event_online_url}`, and the admin registrations dashboard still shows it. `BLT_Events_Helpers::get_event_location_string()`, `get_calendar_invite_description()` and `generate_ics_content()` take a new `$include_online_url` argument (default `false`), and `blt_events_ics_content` receives it as a third parameter.

## 2.4.0

### Fixed

- **Stripe: a PaymentIntent could confirm more than one registration.** The confirm endpoint now refuses an intent that already produced a registration and returns the existing one, so a single payment can never be replayed with different attendee details.
- **Stripe: payments could complete without a registration.** The submitted form is stashed against the intent; if the browser never returns, the `payment_intent.succeeded` webhook creates the registration from the stash. Paid orders that still cannot be turned into a registration fire `blt_events_payment_orphaned`.
- **Free registration on paid events.** Submitting the direct-registration endpoint without ticket quantities on an event that sells tickets used to create a confirmed free registration. Events with ticket types now require a selection, and the endpoint refuses any total above zero.
- **Manual confirmation sent nothing.** Every status change goes through `update_status()`, which fires `blt_registration_confirmed` / `blt_registration_cancelled` / `blt_registration_refunded` exactly once per transition. Confirming from the Registrations screen or the REST API now sends the confirmation email, tags the FluentCRM contact and cross-registers attendees into meeting rooms.
- **Calendar invites and Google Calendar links were off by the site's UTC offset.** All date math uses the site timezone.
- **Rate limit behind Cloudflare / proxies.** The client IP is read from forwarding headers (filterable), and a per-email limit was added, so one busy site no longer locks everyone out after ten registrations.
- **Refunded registrations no longer count towards capacity**; refunded and cancelled emails may register again.
- Admin styles loaded on other BLT plugins' screens because of a `blt-` prefix match.
- Database, roles and cron are installed or upgraded on sites updated without re-activation (`BLT_EVENTS_DB_VERSION`).
- Coupon validation is rate-limited.

### Added

- **Reminder emails** (24 hours and 1 hour before) are actually sent, by a WP-Cron task every 15 minutes, once per event, with per-reminder toggles.
- **Email overhaul.** From name / address / Reply-To, admin notification of new registrations, pending-approval emails, optional branded HTML wrapper (overridable template), formatted dates in placeholders, and new placeholders (`{tickets}`, `{total}`, `{ics_url}`, `{event_online_url}`, `{site_name}`, ...). Every email passes through filters for recipient, subject, body, headers and attachments.
- **Multi-attendee details.** Per event, collect name/email/phone (filterable) for each additional ticket; attendee rows are stored per seat with their ticket type.
- **Fieldset builder:** new field types (radio, checkbox group, country, hidden, text block), validation rules (length, pattern, min/max, custom message), conditional logic (show a field when another has a value), default values and help text, presets for new fieldsets, duplicate, set default. Server-side and client-side enforcement.
- **Templates.** All front-end HTML lives in `templates/` and can be overridden from `your-theme/blt-events/`.
- **Blocks.** "Events Calendar" and "Event Registration Form" blocks (server-rendered wrappers of the shortcodes) with inspector controls.
- **Event archive.** `/event/` and category archives are rendered by the plugin (classic template or a registered block template), redirected to the Events page, or left to the theme.
- **Structured data.** schema.org `Event` JSON-LD on single event pages.
- **REST.** Event meta is registered for the REST API and a computed `blt_event` field exposes formatted data.
- **Roles.** The event post type has its own capabilities; an "Event Manager" role bundles them with the plugin's screens.
- **Exports.** Attendees CSV (one row per seat), fieldset columns in the registrations CSV, filters for columns and rows.
- **Featured events**: `featured="yes"` on the shortcode/block, badge in listings.
- "Add to calendar" links on the event page; `uninstall.php` with an opt-in data purge; multisite activation; PHPUnit tests, phpcs config and CI; a POT file and translatable JavaScript.

### Changed

- The default fieldset on **new installs** is generic (name, email, phone) and links to the site's privacy policy. Existing fieldsets are untouched.
- Prices show the `$` symbol by default when the setting was never saved.
- On **new installs** the plugin does not print its own H1 on event pages (themes already do) and renders the event archive itself. **Upgrades keep the previous behaviour**; switch these on under Settings > Appearance and Settings > General. Likewise reminders, admin notifications and the HTML email wrapper are on for new installs and off on upgrades until enabled.
- `BLT_Events_Registrations::send_confirmation_email()` is deprecated in favour of `BLT_Events_Emails::send()`.
- Plugin headers declare `Requires at least`, `Requires PHP`, `Domain Path` and `Update URI`.

## 2.3.x

See the GitHub releases for earlier history.
