# BLT Events

Event registration for WordPress. Calendar, list and grid views, ticket types with sale windows and role restrictions, configurable registration forms, multi-attendee bookings, reminders, coupons, and checkout through Stripe, SureCart or FluentCart. Online and hybrid events can auto-create Zoom, Microsoft Teams, GoTo or ClickMeeting rooms.

Part of the S-FX.com **BLT** plugin family. Conventions shared across the family live in [DESIGN.md](DESIGN.md); the admin component library in [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md); every hook in [HOOKS.md](HOOKS.md).

## Requirements

- WordPress 6.2 or newer (6.7+ for the block-theme archive template)
- PHP 7.4 or newer
- Pretty permalinks

## Install

1. Upload the plugin zip from the [latest release](https://github.com/S-FX-com/BLT-Events/releases) via Plugins > Add New, or clone this repository into `wp-content/plugins/blt-events`.
2. Activate. Tables, a default registration form, roles and the reminder task are created.
3. Open **Events > Settings**. The setup card lists anything still missing and fixes what it can in one click, including creating the Events page.
4. Add an event under **Events > Add New**.

Updates are served from GitHub releases through the bundled update checker; the release workflow builds the zip.

## What works out of the box

| Area | Default behaviour |
|---|---|
| Events page | Created for you from the setup card, holding the Events Calendar block (or shortcode on classic-editor sites) |
| `/event/` archive | Rendered by the plugin with search and a view switcher; category archives too |
| Event page | Renders inside your theme's single template: date box, add-to-calendar links, CTA, address with map, join link for confirmed registrants, presenters, agenda, registration form |
| Registration form | A three-step checkout: **Registration** (ticket types, quantities, subtotal), **Attendee details** (one card per attendee beside an event summary) and **Review & payment**. Free orders skip the payment step. Logged-out visitors see a **Log in for Member Rates** prompt when a role-restricted ticket is on sale |
| Emails | Confirmation with .ics attached, pending notices, admin notification, 24h/1h reminders |
| Styling | Styled mode with its own design; Skeleton mode inherits your framework's CSS variables; No-CSS mode leaves BEM markup |
| SEO | schema.org Event JSON-LD on every event page |

## Blocks and shortcodes

**Events Calendar** block / `[blt_events_calendar]`

| Attribute | Default | Description |
|---|---|---|
| `view` | `list` | `list`, `grid` or `calendar` (month grid) |
| `category` | | Event category slug(s), comma-separated |
| `limit` | `12` | Max events in list/grid views |
| `past` | `no` | `yes` to include past events |
| `switcher` | `no` | `yes` to show the List / Grid / Month switcher |
| `featured` | `no` | `yes` to show only featured events |

**Event Registration Form** block / `[blt_event_registration event_id="123"]`

Renders the form for one event. Inside an event page the ID is optional.

Settings > Shortcodes & Blocks has a builder that composes the shortcode for you.

## Customising

### Settings

- **General**: events page, archive mode, date format, currency display, maps, structured data, uninstall behaviour.
- **Appearance**: styling mode, accent colour, radius, typeface, width; whether the plugin prints the title, featured image, back link and calendar links on event pages.
- **Payments**: enable Stripe, SureCart, FluentCart; site default; per-event override in the event editor.
- **Emails**: sender, admin recipient, HTML wrapper, every template's subject and body, reminders, calendar invite.
- **Integrations**: Zoom, Teams, GoTo, ClickMeeting credentials; presenter post type mapping; FluentCRM lists and tags.

### Registration forms (fieldsets)

Events > Fieldsets. Start from a preset, then add fields of any type: text, email, phone, URL, number, date, paragraph, dropdown, radio, single checkbox, checkbox group, country, hidden value, text block. Each field can be required, half or third width, carry validation rules (length, pattern, min/max, custom message), show conditionally based on another field, prefill from the logged-in user's profile or an ACF field, and push to a FluentCRM contact field. Assign a fieldset per event or set one as the default.

### Templates

Every piece of front-end HTML is a file under `templates/`. Copy any of them to `your-theme/blt-events/<same path>` and edit; the theme copy is used instead.

```
templates/
├── single-event.php            Event page layout
├── single/                     Its parts: title, datebox, cta, address, virtual, agenda, presenters, ...
├── calendar/                   list-item, grid-card, month-event, empty
├── registration/               form, attendee, summary, member-login, closed, surecart, fluentcart
├── emails/wrapper.php          HTML shell around every email
└── archive-event.php           Classic-theme event archive
```

Templates receive their data as local variables (documented at the top of each file) and in an `$args` array.

### CSS

All colours, radii, shadows and fonts resolve through `--blt-e-*` custom properties defined in `assets/css/blt-events-tokens.css`. Redefine any of them in your stylesheet:

```css
:root {
	--blt-e-primary: var(--action);
	--blt-e-radius: var(--radius-m);
	--blt-e-font: var(--body-font);
}
```

Skeleton mode does this automatically against Automatic.css and common framework variable names.

### Hooks

Around 90 actions and filters cover queries, markup, emails, pricing, validation, field types, statuses, exports and more. See [HOOKS.md](HOOKS.md).

### REST API

The `event` post type is available at `/wp-json/wp/v2/event`. Public meta (`_blt_event_date`, `_blt_event_type`, `_blt_ticket_types`, ...) is registered, and a computed `blt_event` field returns formatted start/end, labels, location, price range, ticket availability and spots left.

The plugin's own namespace, `/wp-json/blt-events/v1/`, offers registrations, attendee check-in and fieldsets for users who can manage events, plus a public `.ics` download and per-event fieldset lookup. Stripe webhooks post to `/blt-events/v1/stripe-webhook`.

### Roles and capabilities

The event post type uses its own capabilities (`edit_blt_events`, `publish_blt_events`, ...). Administrators and editors get all of them plus `manage_blt_events` (the plugin's admin screens); authors and contributors mirror their post permissions. An **Event Manager** role bundles everything needed to run events without touching the rest of the site.

## Development

```bash
composer install
composer lint     # php -l on every file
composer phpcs    # WordPress Coding Standards
composer test     # PHPUnit (no database needed)
node bin/make-pot.js   # regenerate languages/blt-events.pot
```

CI runs syntax checks on PHP 7.4 to 8.3, phpcs, the test suite and a POT drift check on every push and pull request. Merges to `main` publish a GitHub release with the installable zip.

## Data

Custom tables: `{prefix}blt_fieldsets`, `{prefix}blt_registrations`, `{prefix}blt_attendees`. Events and coupons are post types (`event`, `blt_coupon`). Deleting the plugin removes nothing unless "Delete all plugin data" is enabled under Settings > General > Advanced.

## License

GPL-2.0-or-later. © S-FX.com.
