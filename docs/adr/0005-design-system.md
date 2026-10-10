# ADR 0005 — Design system: warm frosted glass

- **Status:** Accepted
- **Date:** 2026-10-08
- **Phase:** 1

## Context

The spec (section 22) asks for a premium interface with its own visual identity, one design system across the
Super Admin panel, the workspace and every app, light and dark mode, WCAG AA contrast, and a typeface that renders
Bengali well. The owner additionally asked for a distinctive glassmorphism style that does not read as a generic
AI-generated template, which in practice means avoiding the dark-navy, purple-gradient look.

Frontend packages checked before installing (MIT unless noted): vue 3.5, @inertiajs/vue3 3.8, vue-i18n 11,
reka-ui 2 (accessible dialog and menu primitives), lucide-vue-next 1 (ISC), ziggy-js, vitest 5, typescript
(Apache-2.0), vue-tsc. Fonts come from fontsource and are OFL-1.1. The spec's own font suggestions are OFL too,
so OFL-1.1 is recorded here as an accepted licence **for fonts only**.

## Decision

**"Warm frosted glass":** translucent, blurred panels (`backdrop-filter`) floating over a warm, softly coloured
backdrop with a fine grain.

| Aspect | Decision | Where |
|---|---|---|
| Palette, light | Bone canvas `#ece7df`, deep pine brand `#0f6b57`, persimmon accent `#c9481f` | `resources/css/app.css` (`:root`) |
| Palette, dark | Green-black canvas `#0a110f`, mint brand `#3fc9a4` | `[data-theme='dark']` |
| Tokens | CSS custom properties, exposed to Tailwind 4 through `@theme inline`. The stock Tailwind palette is switched off (`--color-*: initial`), so only token colours compile | `app.css` |
| Surfaces | Utilities `glass` (standard panel: 72% white in the light theme, a tinted 62% in the dark one), `glass-solid` (menus, dialogs, toasts), `glass-well` (recess). Soft status fills are tinted chips at 57–66% opacity | `app.css` |
| Backdrop | Three colour fields, a few solid discs for the blur to refract, SVG film grain. No disc is placed above 80% opacity | `.backdrop`, `Core/Components/Backdrop.vue`, `Core/Layouts/AuthLayout.vue` |
| Layout signature | Sidebar and top bar are detached floating slabs, not edge-to-edge chrome | `Core/Components/Shell/AppShell.vue` |
| Type | Bricolage Grotesque (display, figures), Hanken Grotesk (text), Hind Siliguri (Bengali glyphs), JetBrains Mono (labels, ids). Self-hosted through fontsource | `app.css` imports, `--font-*` |
| Themes | `data-theme` on `<html>`, set before first paint by an inline script, choice kept in `localStorage` (`oyo:theme`: light, dark or follow the system) | `resources/views/app.blade.php`, `composables/useTheme.ts` |
| Components | One set for the workspace, the Super Admin panel and the central site, built on reka-ui primitives and lucide icons. The panel's own composites build on it | `resources/js/Core/Components`, `resources/js/Platform/Components` |
| Layouts | `AuthLayout`, `TenantLayout`, `PlatformLayout`; the last two share `AppShell` | `resources/js/Core/Layouts` |

Rules:

- Colours, radii and shadows come from tokens. No hard-coded colours and no `dark:` colour overrides.
- WCAG AA (4.5:1) text contrast on glass is a requirement, and it is tested: `tests/js/contrast.test.ts`
  recomputes it from the tokens in `app.css` for every text and surface pair the components produce, over every
  backdrop colour including the middle of a disc at the strongest opacity any layout uses.
- `prefers-reduced-transparency`, and browsers without `backdrop-filter`, get near-solid surfaces.
  `prefers-contrast: more` strengthens lines and subtle text. `prefers-reduced-motion` removes animation.
- No gradient text, no emoji, no decorative sparkle. Brand green marks primary actions and current state; the
  accent is used sparingly.
- Bengali gets its own line height and letter spacing (`:lang(bn)`), and widths are never fixed around text.

The working guide for building screens is `resources/js/README.md`.

## Consequences

- A page cannot use `bg-gray-100` or `text-blue-600`: those classes do not exist. This is intended.
- The first token set did not meet AA over the discs (about 3.9:1 for subtle text in the light theme, far lower
  in the dark one, where glass was 5.5% white). The tokens were retuned: glass carries more of its own colour,
  status fills became heavier chips, `--accent-text` was added next to `--accent`, the dark discs were
  deepened. The glass is therefore less transparent than a pure "frosted" look would have it.
- The guarantee is exactly what the test models: the tokens, the `saturate()` of the glass and a disc at up to
  80% opacity. The sheen and the film grain are left out, and nothing is measured in a rendered browser. A new
  token used for text or a surface, or a disc above 80%, has to go through the test.
- The theme is per device (localStorage), not per account.
- Server-named things (permissions, audit events, navigation keys) need entries in the client catalogue
  (`Core/i18n/locales/*/catalogue.ts`, `shell.ts`), and icon names need registering in `Core/Components/Icon.vue`.
- Bengali is complete for Phase 1: all twelve client namespaces under `Core/i18n/locales/bn` (with a glossary and
  style rules in `bn/index.ts`), `lang/bn/*.php` and `lang/bn.json`. The client catalogue is typed against the
  English one and `tests/js/locales.test.ts` compares keys and placeholders, so a missing translation fails.
- Four font families are shipped. Hind Siliguri is loaded for the Bengali range only, so Latin text and digits
  stay in Hanken Grotesk.
