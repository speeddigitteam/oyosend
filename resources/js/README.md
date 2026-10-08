# Frontend guide

Vue 3 (`<script setup lang="ts">`) + Inertia 3 + Tailwind 4 + vue-i18n. Read this before adding or changing a page.

## Layout of this folder

```
app.ts                      entry; resolves pages by path
Core/
  Components/               the design system (one set for all three areas)
    Shell/                  sidebar, top bar, command palette, menus
    Widgets/                dashboard widgets
  Layouts/                  AuthLayout, TenantLayout, PlatformLayout
  Pages/                    core workspace pages (+ Central/, Errors/)
  composables/              useFormat, useAccess, useTheme, useToasts
  lib/                      route(), format helpers, label lookups
  i18n/locales/{en,bn}/     one file per area of the interface
  types/                    props shared by every page
Platform/Pages/             Super Admin panel pages
Modules/<Module>/Pages/     a module's pages (and Components/)
```

A page's Inertia name is its path under `resources/js` without the extension: `Core/Pages/Team/Members`, `Platform/Pages/Dashboard`, `Modules/CRM/Pages/Leads/Index`.

## The design language: warm frosted glass

Translucent, blurred panels floating over a warm backdrop that carries a few solid discs. It should look made by a person with a point of view, not generated.

**Do**

- Build screens from `Card` (the glass panel) on the page background. Never put a panel inside a panel just for decoration; inside a card use hairlines (`border-line`), `glass-well` recesses and spacing.
- Use the type roles: page and card titles in the display face (any `h1`–`h3`, or `font-display`), body in the default face, and the `eyebrow` utility (small mono caps) for section labels, table headers and metadata. Big numbers use the `figure` utility; any column of numbers uses `tabular`.
- Use only token colours: `text-ink`, `text-ink-muted`, `text-ink-subtle`, `bg-hover`, `border-line`, `border-line-strong`, `text-brand-text`, `bg-brand-soft`, `text-accent-text` (`text-accent` only for figures of 24px and up, and `bg-accent` for small marks), and the status pairs `text-ok`/`bg-ok-soft`, `warn`, `danger`, `info`. The stock Tailwind palette (`gray-500`, `blue-600`, …) is switched off and will not compile to anything.
- Brand pine green is for primary actions and "current" states. Persimmon `accent` is rare: one thing per screen that deserves the eye (a count that needs attention, the active nav marker).
- Radii: `rounded-panel` (shell slabs, dialogs), `rounded-card`, `rounded-control` (buttons, inputs), `rounded-chip` (badges).
- Keep it dense and calm: 14px body, generous line-height, `gap-3` between cards, `px-5` inside them.
- Write like a person: short, specific, sentence case. "Invite member", "Nothing here yet", "You can only grant permissions you hold yourself."

**Do not**

- No gradient text, no gradient buttons, no neon glows, no purple/indigo, no emoji, no sparkle or "AI" iconography, no decorative illustrations.
- No hard-coded colours or `dark:` overrides for colour — tokens already switch with the theme. (`dark:` is fine for the rare non-colour tweak.)
- No raw `<button>`/`<input>`/`<select>`/`<table>` where a component exists.
- No marketing voice ("Supercharge", "Seamlessly", "Unlock") and no exclamation marks.
- Do not show a control for something the person cannot do or that does not exist yet.

Every text colour must keep WCAG AA contrast on glass; the tokens are tuned for it, so stay on them. Everything must work with the keyboard and in both themes, and at 360px wide.

## Components (`@/Core/Components`)

Read the component file for the full prop list; each has a doc comment.

| Component | Use |
|---|---|
| `Button` | `variant` primary / secondary / ghost / danger; `size` sm / md / lg; `href` makes it an Inertia link; `loading`; `block`; `icon` for a square icon button (put the label in the slot, it becomes screen-reader text); `#icon` slot for a leading icon |
| `Input`, `PasswordInput` | `v-model`, `label`, `hint`, `error`, `optional`, `mono`; `#leading` / `#trailing` / `#aside` slots; other attributes fall through to the `<input>` |
| `Select` | native select; `options: {value,label}[]`, `placeholder`, `label`, `error` |
| `Checkbox` | `v-model` boolean, `label`, `description`, `indeterminate` |
| `Field` | label + hint + error around a custom control |
| `SegmentedControl` | a few exclusive choices side by side |
| `Card` | glass panel; `title`, `description`, `flush` (no body padding, for tables); `#actions`, `#footer` |
| `PageHeader` | `title` (also sets the document title), `eyebrow`, `description`, `#actions` |
| `DataTable` | `columns`, `rows`, `row-key`, `caption`; `#cell-{key}="{ row, value }"`; sortable and hideable columns; `#toolbar`, `#empty` |
| `Pagination` | pass the paginator object (`meta`); works for length-aware and cursor paginators |
| `Badge` | `tone` neutral / brand / accent / ok / warn / danger / info, `dot` |
| `Modal`, `ConfirmDialog` | `v-model:open`, `title`, `description`; `#footer` |
| `Dropdown`, `DropdownItem`, `DropdownLabel`, `DropdownSeparator` | menu button; `DropdownItem` takes `href` or emits `select`, `tone="danger"` |
| `EmptyState` | `title`, `description`, `#icon`, actions in the default slot |
| `Skeleton` | loading placeholder; size with utilities |
| `Avatar` | `initials`, `name` (tint seed), `size`, `square` |
| `StatTile` | one headline number |
| `Kbd`, `Spinner`, `Logo`, `Backdrop`, `Icon` | small pieces |
| `TwoFactorEnrolment`, `RecoveryCodes` | the two-factor enrolment blocks |
| `Toaster` | already mounted by every layout; do not mount it again |

Icons: import from `lucide-vue-next`, `class="size-4"`, `aria-hidden="true"`. `Icon` (by name) is only for icon names that come from the server.

## Pages

```vue
<script setup lang="ts">
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';

defineOptions({ layout: TenantLayout });           // persistent layout

defineProps<{ members: Paginated<Member> }>();     // type every prop
</script>
```

- Layouts: `TenantLayout` (signed-in workspace), `PlatformLayout` (Super Admin), `AuthLayout` (signed out; pass props with `layout: (_h, page) => h(AuthLayout, { plain: true }, () => page)`, where `h` is the one imported from `vue` - Inertia types its own as taking two arguments).
- Start a page with `PageHeader`. Use `Head` only on pages without one.
- Forms: Inertia `useForm`; pass `form.errors.x` to the control's `error`; disable/`loading` the submit while `form.processing`. Destructive actions go through `ConfirmDialog`.
- URLs: always `route('name', params)` from `@/Core/lib/route`. Only the current area's routes are available in the browser.
- Success and failure messages are flashed by the server and shown by `Toaster` automatically; do not duplicate them client-side.
- Permissions: `const { can } = useAccess()` to hide controls. It is presentation only — the server authorises every request.
- Formatting: `const format = useFormat()` → `format.number()`, `format.date(iso, 'date' | 'datetime' | 'time' | 'short')`, `format.relative(iso)`, `format.money(minorUnits)`. Never print a raw number or ISO string: digits follow the person's numeral setting (Latin or Bengali) and dates the workspace timezone.
- Every state is designed: loading (`Skeleton` / `loading`), empty (`EmptyState`), validation errors, and the "you may view but not change this" case.

## Text and translations

- No user-facing string in a template. Add it to `Core/i18n/locales/en/<area>.ts` and use `t('area.key')`. `bn/` mirrors `en/` and is type-checked against it.
- vue-i18n treats `@`, `|` and `{}` specially: write `{name}` for parameters and avoid `@` and `|` in messages.
- Server-side keys that contain dots (permissions, audit events) are translated with `permissionLabel()`, `permissionDescription()`, `eventLabel()` … from `@/Core/lib/labels`, backed by `locales/*/catalogue.ts`.
- Bengali text is longer and taller than English. Do not fix widths or heights around text; let it wrap.

## Checks

```
npm run typecheck     # vue-tsc, strict
npm run test          # vitest
npm run build
```
