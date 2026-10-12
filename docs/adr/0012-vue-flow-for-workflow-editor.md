# ADR 0012 - Vue Flow for the workflow editor

- **Status:** Accepted
- **Date:** 2026-10-11
- **Phase:** 10 (Workflow Automation, frontend)

## Context

The Workflow Automation app (`docs/WORKFLOWS.md`) stores a workflow as JSON: nodes with a type, a position and a
declarative configuration, and edges that leave a node through a named output. The spec asks for a visual builder
on a canvas. Drawing and hit-testing a node graph (pan, zoom, drag, connection lines that follow handles, selection,
focus) is a large amount of fiddly code that is not what makes the product different, so a library is worth its
weight here. The licence rules (MIT, BSD, Apache-2.0, ISC only) and Laravel 13 / Vue 3 / Vite 7 compatibility had to
be checked before installing anything.

## Decision

Use **Vue Flow**: `@vue-flow/core` 1.48.2 and `@vue-flow/background` 1.3.2 (dev dependencies, like the rest of the
front-end toolchain; Vite bundles them).

| Package | Licence | Why |
|---|---|---|
| `@vue-flow/core` | MIT | Vue 3 native (`peerDependencies: vue ^3.3`), TypeScript types, custom node components, named handles, v-model for nodes and edges |
| `@vue-flow/background` | MIT | The grid behind the canvas; `peerDependencies: @vue-flow/core ^1.23, vue ^3.3` |

Transitive packages added: `d3-drag`, `d3-zoom`, `d3-selection`, `d3-interpolate`, `d3-color`, `d3-dispatch`,
`d3-transition`, `d3-timer` (ISC), `d3-ease` (BSD-3-Clause), `@vueuse/core`, `@vueuse/shared`, `@vueuse/metadata`,
`vue-demi`, `@types/web-bluetooth` (MIT). All are within the allowed licences. `@vue-flow/controls` and
`@vue-flow/minimap` were considered and **not** installed: the zoom and fit buttons are our own translated
controls, and a minimap is not needed at the size of graph the server allows (100 nodes).

`vue-demi` declares a `postinstall` script that npm reports as not covered by `allowScripts`. It only selects the
Vue 2 or Vue 3 build and the project builds and tests without running it, so it is left unapproved.

How it is used (and what is deliberately ours):

- **Our code owns the graph rules.** `resources/js/Modules/Automation/lib/graph.ts` is pure TypeScript: the wire
  format, conversion to and from Vue Flow nodes and edges, handle mapping (the unnamed output is `null` on the wire
  and `__out` on the canvas), the connection rules the server enforces, loop-back detection, outline and issue
  grouping. Vue Flow only draws and moves things; it never decides what is a valid graph. The server remains the
  authority (`GraphValidator`), and the editor shows its answer as you work.
- **One custom node type renders every step.** Vue Flow's runtime fields (dimensions, computed positions,
  selection) never reach the server: `fromFlow()` copies only the known keys.
- **Everything the canvas can do is also possible without it.** The side panel lists every output of the selected
  step and lets the keyboard user pick the step it leads to, because dragging between handles is not accessible.
  Below 768px the page shows a read-only outline instead of the canvas.
- **No free code.** Step settings are choices from the server's catalogue, numbers, text, or a value from the run
  inserted as `{{ path }}`. Nothing the person types is evaluated in the browser.
- The editor's page chunk (and the run graph) load lazily with the page, so the library is not part of the main
  bundle.

## Consequences

- Upgrading Vue Flow is a contained change: only `EditorCanvas.vue`, `RunGraph.vue` and `WorkflowNode.vue` import it.
- The canvas styles (handles, edges, focus ring) are our own CSS using design tokens, because Vue Flow's default
  theme hard-codes colours that would break the dark theme and the contrast tests.
- Vue Flow's built-in controls have untranslatable labels, which is why they are not used.
