# Changelog

All notable changes to BLT Events. Versions follow [Semantic Versioning](https://semver.org/).

## 2.4.4

### Added

- **Sponsors.** A new section on the single event page — a logo row on the front end, with its own admin repeater (logo + optional link) and drag-to-reorder, mirroring Presenters.
- **Registrations Trash.** Move to Trash / Restore / Delete Permanently for registrations in the admin list, matching WordPress's native post-trash pattern (views, bulk actions, row actions).
- Agenda, Presenters and Sponsors admin rows are drag-to-reorder.

### Changed

- **Single event sidebar.** The four separate cards (date, price/CTA, address, presenters) are now one sticky info card; every field hides independently when empty, so the card itself only disappears if all fields are empty.
- **Agenda** is now a native accordion: opening one session closes the others.
- **Registration form** is widened to match the page instead of floating as a separate narrow widget, and restyled to match the site design.
- **Event Calendar list view toolbar** rebuilt to match the approved design: bordered prev/next buttons, a styled Today / This Week / This Month date-range filter, and an icon-only search button.
- **Event Calendar list rows** rebuilt to match the approved design (day/weekday, datetime, title, location, featured image); mobile layout adjusted so the date-range dropdown fills the toolbar and the day/weekday column hides in favor of the inline datetime.

### Fixed

- Sidebar consolidation had dropped the "Free" price label, the event's time, and presenter bios for events without a paid ticket, a set time, or a bio.
- Free events were routed through an unconfigured SureCart checkout instead of the standard RSVP path.
- Registrations CSV export included trashed registrations.
- Attendee check-in percentage could read over 100% if a checked-in registration was later cancelled, refunded, or trashed.
- Event excerpt now falls back to a trimmed excerpt of the post content when no manual excerpt is set, instead of showing nothing.
- Corner Radius setting under Appearance → Overrides now actually applies to the calendar list view (was previously hardcoded to square corners).
- Skeleton mode now pulls real Automatic.css (ACSS) variables for colours, borders, shadows and corner radius instead of falling back to incorrect guessed variable names; content width now matches the site's real ACSS max content width, so switching between Styled and Skeleton mode produces no layout differences.
- Plugin update-checker's bundled `vendor/` folder was silently stripped from release builds.

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
