---
status: final
updated: 2026-06-11
sources:
  - prd: '_bmad-output/planning-artifacts/prds/prd-wordpress-2026-06-10/prd.md'
  - architecture: '_bmad-output/planning-artifacts/architecture.md'
  - existing_system: 'plugin/skwirrel-pim-sync/assets/dashboard.css'
colors:
  brand:
    blue: '#5F84C1'
    blue_light: '#C2D7FF'
    blue_dark: '#304261'
    blue_tint: '#E7EFFF'
    green: '#DDFF6D'
    green_dark: '#B8C53E'
    dark: '#282828'
  text:
    strong: '#111827'
    body: '#1f2937'
    label: '#374151'
    muted: '#6b7280'
    faint: '#9ca3af'
  border:
    default: '#e5e7eb'
    input: '#d1d5db'
    subtle: '#f3f4f6'
  surface:
    page: '#f0f0f1'   # wp-admin body
    card: '#ffffff'
    raised: '#f9fafb'
  status:
    success_fg: '#065f46'
    success_accent: '#059669'
    success_bg: '#ecfdf5'
    success_border: '#a7f3d0'
    error_fg: '#991b1b'
    error_accent: '#dc2626'
    error_bg: '#fef2f2'
    error_border: '#fecaca'
    warning_fg: '#92400e'
    warning_accent: '#d97706'
    warning_bg: '#fffbeb'
    warning_border: '#fde68a'
    danger_wp: '#d63638'
  log_dark:
    bg: '#1e1e2e'
    text: '#cdd6f4'
    info: '#89b4fa'
    warning: '#f9e2af'
    error: '#f38ba8'
    debug: '#6c7086'
typography:
  font_sans: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'
  font_mono: 'ui-monospace, "SF Mono", Monaco, "Cascadia Code", monospace'
  size_xs: '11px'
  size_sm: '12px'
  size_base: '13px'
  size_md: '14px'
  size_lg: '16px'
  size_xl: '18px'
  weight_medium: 500
  weight_semibold: 600
  weight_bold: 700
  numeric: 'tabular-nums'
rounded:
  sm: '4px'
  md: '6px'
  base: '8px'    # --skw-radius
  lg: '12px'     # --skw-radius-lg
  pill: '9999px'
spacing:
  scale: [4, 6, 8, 10, 12, 14, 16, 20, 24, 30, 32, 40]
  content_max: '960px'
  field_max: '912px'
elevation:
  card: '0 1px 3px rgba(0,0,0,0.08)'
  modal: '0 20px 60px rgba(0,0,0,0.4)'
  focus_ring: '0 0 0 3px rgba(95,132,193,0.15)'
components:
  - header_bar
  - status_card
  - action_block_grid
  - section_card
  - progress_ledger
  - history_table
  - badge
  - settings_form
  - button
  - danger_zone
  - log_modal
  - live_log
---

# Skwirrel PIM Sync — Visual Identity (DESIGN.md)

> This spine **distills the design system already shipping** in `assets/dashboard.css`
> (scoped to `.skw-dashboard`). It is the source of truth for *how it looks*; it wins on
> conflict with any mock. New surfaces (Health, Preflight, Guided Setup, Reset) inherit
> these tokens and components — no new visual language is introduced.

## Brand & Style

Calm, legible, **wp-admin-native but quietly branded**. The plugin reads as a first-class
WooCommerce extension, not a foreign app: it sits inside `wp-admin`, respects its rhythms, and
adds one dark **Skwirrel header bar** and a restrained blue/lime palette on top. Tone is
**reassuring and plain-spoken** — this audience is non-technical store owners, so the visual
language favours whitespace, soft card surfaces, gentle status colour, and never a wall of raw log.

Voice of the surface: *confident, low-drama, honest*. Success is quietly green; problems are clear
but not alarmist; the one genuinely dangerous action (reset) earns the only loud red.

## Colors

Brand blue `{colors.brand.blue}` is the primary action/identity colour; `{colors.brand.dark}`
is the header bar and strongest text. The lime `{colors.brand.green}` is a sparing accent (logo
lockup / highlight), never a button fill. Everything else is a Tailwind-style neutral ramp
(`{colors.text.*}`, `{colors.border.*}`, `{colors.surface.*}`).

Status colour is **semantic and bounded**:
- **Success** — `{colors.status.success_*}` (last sync OK, healthy check).
- **Warning** — `{colors.status.warning_*}` (degraded, "could not determine", paused).
- **Error / problem** — `{colors.status.error_*}` (failed sync, failing check).
- **Danger (WP red `{colors.status.danger_wp}` / `{colors.status.error_accent}`)** — reserved for
  destructive actions only (reset, delete-all). Never for ordinary errors.
- **Log dark theme** — `{colors.log_dark.*}` for the monospace log viewer only.

Contrast: body text `{colors.text.body}` and labels `{colors.text.label}` on white clear AA;
muted `{colors.text.muted}` is for secondary text only, never primary content.

## Typography

`{typography.font_sans}` (Inter) throughout; `{typography.font_mono}` for logs, IDs, SKUs, and
code-like values. Scale is tight: body `{typography.size_base}` (13px), section titles
`{typography.size_lg}`, page/header title `{typography.size_xl}`, metadata
`{typography.size_sm}`–`{typography.size_xs}`. Weights: `{typography.weight_medium}` labels,
`{typography.weight_semibold}` titles/buttons, `{typography.weight_bold}` emphasis/header.
**All counts use `{typography.numeric}` (tabular-nums)** so columns of numbers align — critical for
the history table and the preflight/result change counts.

## Layout & Spacing

Single-column, centred content at `{spacing.content_max}` (960px). The dark header bleeds to the
wp-admin edge (negative margins); content sits in white **section cards** on the wp-admin page
surface. Spacing follows the `{spacing.scale}` step set (4→40). Forms use a 2-column field grid that
collapses to one column under 782px. Generous 24px card padding; 24px vertical rhythm between sections.

## Elevation & Depth

Mostly flat. Cards carry a barely-there shadow `{elevation.card}`; the action-block grid uses 1px
gutters over a neutral bg to read as a tiled surface. Inputs/selects show a blue focus ring
`{elevation.focus_ring}`. Only the **modal** (log viewer) uses real depth `{elevation.modal}`.

## Shapes

Rounded, friendly, consistent: inputs/buttons `{rounded.md}` (6px), cards/sections `{rounded.lg}`
(12px), icon tiles `{rounded.base}` (8px), **badges `{rounded.pill}`**. Status dots are circles.

## Components

Behavioural specs live in EXPERIENCE.md; this is the visual contract. All exist today in
`dashboard.css` — reuse, don't reinvent.

- **header_bar** — full-bleed `{colors.brand.dark}` bar; logo (`assets/s.png`) + title + subtitle;
  optional back-link (translucent white). Anchors every plugin screen.
- **status_card** — last-result banner; success/error variants (`{colors.status.*}`) with leading
  icon, title, meta line. Extends to a **paused** (warning) variant for FR-10.
- **action_block_grid** — 2-col tile grid of navigational cards (icon tile + title + desc + arrow);
  hover raise, focus → `{colors.brand.blue_light}`. The hub's primary navigation.
- **section_card** — white `{rounded.lg}` card with header (title + desc + optional right-aligned
  link) and body. The default container for everything below the hub.
- **progress_ledger** — evolution of the existing phase banner: a compact progress block showing
  **resumable per-item progress** — "X of Y products" (committed / total, tabular-nums) + a status line;
  warning-tinted when paused; Resume + abort. *(Replaces the 7-phase list — confirmed.)*
- **history_table** — sticky-head, date-grouped rows, hover, purge-row warning tint; outcome **badge**.
- **badge** — pill, `{typography.size_xs}` semibold; green/red/yellow status variants.
- **settings_form** — fieldgroups with title; label + input/select/affixed-input/checkbox-group;
  field hints; inactive (relation-disabled) state = dimmed + reason line. `[ASSUMPTION]`
- **button** — `.skw-btn` primary (blue), secondary (outline), danger (red). Primary = commit/save.
- **danger_zone** — bordered red-tinted block; danger button; houses Reset (FR-17).
- **log_modal / live_log** — dark `{colors.log_dark.*}` monospace viewer with level colouring,
  pulse "running" dot, download.

## Do's and Don'ts

- **Do** reuse `.skw-*` classes and tokens; new screens must be visually indistinguishable in kind
  from the current dashboard.
- **Do** keep status colour semantic and sparing; reserve red fills for destructive actions only.
- **Do** show counts in tabular-nums; right-align numeric columns.
- **Don't** introduce a second font, a new accent colour, or gradients/heavy shadows.
- **Don't** surface raw logs or stack traces as primary content — they live behind the dark
  log viewer / a "Details" disclosure (non-technical audience).
- **Don't** use the lime `{colors.brand.green}` as a button or large fill — accent only.
- **Don't** let the plugin's chrome fight wp-admin; the dark header is the *only* strong departure.
