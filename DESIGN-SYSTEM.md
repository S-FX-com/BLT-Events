# BLT Events — Admin Design System

This is the template for every admin screen the plugin adds. When building a
new admin page or extending an existing one, reuse the components below
instead of inventing new markup/CSS. It follows the WordPress admin design
language: `#2271b1` accent, `#1e1e1e` text, `#646970` muted text, `#dcdcde`
borders, 8px card radii, toggle switches and pill badges instead of raw
checkboxes/inline-colored text.

There are two systems, because they live in two different structural
contexts. Use whichever matches where your new UI lives.

| | **Custom admin pages** | **Event/Coupon post editor** |
|---|---|---|
| Where | Settings, Registrations, Fieldsets, and any future `add_submenu_page()` screen | Meta boxes on the `event`/`blt_coupon` post edit screen |
| Stylesheet | `assets/css/blt-design-system.css` | `assets/css/event-editor.css` |
| Scope class | `.blt-ui` (add to the page's `.wrap`) | `body.post-type-event` (automatic) |
| Card mechanism | `.blt-card` div you write yourself | WordPress's native `.postbox`, tagged `.blt-card` via `postbox_classes_{screen}_{id}` |
| Color tokens | CSS custom properties on `:root` | The same values, redeclared on `body.post-type-event` (kept separate on purpose — see "Why two systems" below) |

Both use the *same colors*, so the plugin feels like one product, but the
component markup differs because a custom page has full control over its
HTML while a metabox is constrained by WordPress's postbox chrome.

## Adding a new custom admin page

1. Register the page with `add_submenu_page()` (see `class-admin.php`).
2. Make sure its screen hook or slug contains `blt-` (e.g. `blt-my-page`) —
   `blt_events_enqueue_admin_assets()` in `blt-events.php` already loads
   `blt-events-design-system` + `blt-events-admin` (admin.css) on any hook
   matching that pattern, or you can add an explicit `strpos( $hook, ... )`
   branch like the Settings screen has for its own extra CSS/JS.
3. Wrap your page content: `<div class="wrap blt-ui blt-my-page">`. The
   `blt-ui` class is what scopes every component below — without it, none
   of these rules apply.
4. Compose the page from the components in the next section. Only write
   new page-specific CSS for layout that doesn't fit an existing component
   (see `settings.css` / `fieldset-builder.css` for examples of "just the
   parts that are genuinely unique to this page").

**Naming gotcha:** component class names (`.blt-card`, `.blt-field`,
`.blt-toggle`, `.blt-badge`, `.blt-select-card`, ...) are shared globally
under `.blt-ui`. If your page already has an unrelated element that
happens to reuse one of these names — e.g. the Fieldset Builder's
draggable field-item label span used to be `.blt-field-label`, colliding
with the shared field-row label component — rename the page-specific one
(it became `.blt-fitem-label`). Grep for the class name across the page's
PHP/CSS/JS before introducing a shared component to a page that already
has custom markup.

## Component catalog (`.blt-ui` scope)

### Page header
```html
<div class="wrap blt-ui blt-my-page">
  <div class="blt-admin-page-header">
    <h1>Page Title <span class="blt-admin-page-header-sub">Optional subtitle</span></h1>
    <div class="blt-admin-page-actions">
      <a href="#" class="button button-primary">Primary action</a>
    </div>
  </div>
  ...
```

### Card
```html
<div class="blt-card">
  <div class="blt-card-header">
    <h2>Card title</h2>
    <p>One-line description shown under the title.</p>
    <div class="blt-card-header-badges">
      <span class="blt-badge blt-badge-on">Connected</span>
    </div>
  </div>
  <div class="blt-card-body">
    ... fields, a table, anything ...
  </div>
</div>
```
A `<table class="widefat">` (or a WP_List_Table's output) inside
`.blt-card-body` automatically loses its own border/shadow so the card
supplies it once — see `class-registrations-list.php` for a WP_List_Table
wrapped this way.

### Field row
```html
<div class="blt-field">
  <div class="blt-field-label">Label</div>
  <div>
    <input type="text" class="regular-text" />
    <p class="blt-field-desc">Helper text under the control.</p>
  </div>
</div>
```
Stack multiple `.blt-field` rows inside a `.blt-card-body`; each gets a
divider except the first.

### Toggle switch
```html
<label class="blt-toggle">
  <input type="checkbox" name="my_option" value="1" />
  <span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
  <span class="blt-toggle-text">
    <span class="blt-toggle-label">Show currency code</span>
    <span class="blt-toggle-desc">Appends the code after prices, e.g. 25.00 USD.</span>
  </span>
</label>
```
Wrap several in `.blt-toggle-stack` for consistent vertical spacing.

### Badge / status pill
```html
<span class="blt-badge blt-badge-on">Connected</span>
<span class="blt-badge blt-badge-off">Not connected</span>
<span class="blt-badge blt-badge-confirmed">Confirmed</span>
<span class="blt-badge blt-badge-pending">Pending</span>
<span class="blt-badge blt-badge-cancelled">Cancelled</span>
<span class="blt-badge blt-badge-refunded">Refunded</span>
```
Use this for any connected/active/status indicator anywhere in the admin —
don't hand-roll inline `style="color:..."` spans.

### Selectable cards (radio/checkbox card grid)
```html
<div class="blt-select-cards" role="radiogroup">
  <label class="blt-select-card is-selected">
    <input type="radio" name="my_choice" value="a" checked />
    <span class="blt-select-card-check" aria-hidden="true"></span>
    <span class="blt-select-card-name">Option A</span>
    <span class="blt-select-card-desc">One-line description.</span>
  </label>
  ...
</div>
```
Toggle `.is-selected` with JS on `change` (see `settings.js` for the
pattern) — don't rely on `:has()` alone since it's not supported in every
target browser for admin screens.

### Callout
```html
<div class="blt-callout">
  <strong>Heads up</strong>
  <span>One or two lines of context, optionally followed by chips:</span>
  <span class="blt-chips">
    <code class="blt-chip">{example_var}</code>
  </span>
</div>
```

### Code / redirect URI chip
```html
<code class="blt-redirect-uri">https://example.com/callback</code>
```

## Color tokens

Defined in `assets/css/blt-design-system.css` under `:root`, and mirrored
(same values) on `body.post-type-event` in `event-editor.css`:

| Token | Value | Use |
|---|---|---|
| `--blt-primary` | `#2271b1` | Accent, active tab, focus ring, checked toggle |
| `--blt-primary-hover` | `#135e96` | Hover state for primary accents |
| `--blt-primary-tint` | `#f6fafd` | Selected-card background, callout background |
| `--blt-fg` | `#1e1e1e` | Primary text |
| `--blt-muted-fg` | `#646970` | Secondary/helper text |
| `--blt-border` | `#dcdcde` | Card and control borders |
| `--blt-surface` | `#ffffff` | Card background |
| `--blt-surface-muted` | `#f6f7f7` | Card header dividers, code chip background |
| `--blt-success-bg` / `--blt-success-fg` | `#edfaef` / `#005c12` | "Connected"/"Confirmed" badges |
| `--blt-warning-bg` / `--blt-warning-fg` | `#fef8ee` / `#94660c` | "Pending" badges |
| `--blt-danger-bg` / `--blt-danger-fg` | `#fcf0f1` / `#8a2424` | "Cancelled"/error text |
| `--blt-neutral-bg` / `--blt-neutral-fg` | `#f0f0f1` / `#646970` | "Not connected"/"Refunded" badges |
| `--blt-radius` / `--blt-radius-sm` | `8px` / `4px` | Card / control corner radii |

## Why two systems instead of one

The Event/Coupon editor renders inside WordPress's native postbox layout
(drag handles, collapse/expand, screen-options visibility toggles) — it
needs `.postbox` markup that the custom `.blt-ui` pages don't have and
don't want. Rather than force one card implementation to do both jobs, the
editor keeps its own component set (segmented selectors, ticket cards,
role chips — see `event-editor.css`) but now uses the *same color tokens*
as everything else, so switching between "Settings" and "Edit Event" feels
like the same product even though the DOM shapes differ.

## Front-end shortcodes

The public-facing calendar shortcode (`[blt_events_calendar]`) has its own
visual language in `assets/css/calendar.css` (list/grid event cards, the
month calendar grid, the List/Grid/Month view switcher) — it is
intentionally *not* part of the `.blt-ui` admin system, since it renders
on the site's front end inside the active theme, not wp-admin.
