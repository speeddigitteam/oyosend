# Links

Module key `links`, category Productivity, free offer, depends on `core` only. A small first-party organiser for the
websites, tools, email addresses and documents a team keeps coming back to. It follows the standard module shape in
`docs/MODULES.md`; nothing here is a new pattern.

## 1. What it is

- One saved link per row (`links` table): a `kind` (`website`, `tool`, `email`, `document`, `other`;
  `Enums\LinkKind`), a `title`, an `address`, an optional `description`, an optional free-text `folder`,
  `is_pinned`, and the record-owner columns (`owner_id`, `department_id`, `branch_id`).
- **The address is never anything that runs.** An `email` link holds a plain address (`email:rfc`); every other kind
  holds an `http` or `https` URL (`url:http,https`), so `javascript:`, `data:` or `ftp:` never reach the database. The
  page checks again before it builds an `href` (`resources/js/Modules/Links/lib.ts`, `linkHref()`), opens web links in
  a new tab with `rel="noopener noreferrer nofollow"`, and fetches nothing from the address (no favicon, no preview).
- **No secrets.** There is no password or credential field, and the form says to keep passwords in a password
  manager.
- Folders are a plain column, not a table: the form suggests the folders already in use (a `datalist`) and a folder
  disappears when no visible link uses it.
- A link **belongs to the member who saved it** (`App\Core\Access\Concerns\HasRecordOwner`). Reading and changing it
  honour the `own` / `department` / `branch` / `all` scope of its permission through `RecordAccess`. A member with the
  `all` scope sees everyone's links, so a link is not private from them.

## 2. Permissions

Both scopable (`docs/MODULES.md` section 5a):

| Name | Group | What it allows |
|---|---|---|
| `links.links.view` | `links` | See links within the granted record scope |
| `links.links.manage` | `links` | Save, edit, pin and delete links; reassign the owner (needs the `all` scope) |

## 3. Routes

Workspace host, group `['auth:web', 'auth.session', 'member', 'subscribed']` + `entitled:links`:

| Route name | Method | Permission |
|---|---|---|
| `links.index` | GET (`search`, `kind`, `folder`) | `links.links.view` |
| `links.store` | POST | `links.links.manage` |
| `links.update` | PUT | `links.links.manage` |
| `links.destroy` | DELETE | `links.links.manage` |

The controller is thin; writes go through `Actions\LinkManager`, which audits each change
(`links.link.created|updated|deleted`) inside the transaction and dispatches `LinkCreated` after commit.

## 4. What it publishes to other apps

- **Automation trigger** `links.link.created` (`Automation\Triggers\LinkCreatedTrigger`, event `Events\LinkCreated`;
  payload `link_id`, `kind`, `title`, `folder`, `owner_id`, `is_pinned`, never the address). No automation action.
- **Report provider** `links.by_kind` (`Reporting\LinksByKindReport`): links saved in a period by kind, with how many
  are pinned, narrowed by `RecordAccess`. Tagged in `LinksServiceProvider::register()`.
- No Customer 360 section: a link is not tied to a contact or company.

## 5. Frontend

One page, `resources/js/Modules/Links/Pages/Index.vue` (`Inertia::render('Modules/Links/Pages/Index')`): search, a
row of kind filters with counts, a folder filter, a pinned section above the others, a card grid (icon by kind, the
host or email address, description, folder chip that filters, copy / pin / edit / delete), and one modal for adding
and editing. Strings live in the `links` namespace (`resources/js/Core/i18n/locales/{en,bn}/links.ts`); catalogue
labels (module, group, permissions, audit events) are in `catalogue.ts`, the trigger in `automation.ts`, the App Store
text in `appStore.ts`, and the sidebar label is `shell.nav.links` (icon `link`).

## 6. Tests

`tests/Feature/Links/LinksTest.php`: tenant isolation for the model, cross-workspace 404 on the id routes,
not-entitled 404, save/pin/edit/delete, refused addresses (`javascript:`, `data:`, `ftp:`, no scheme, bad email),
filters and kind counts, the `own` scope, a view-only member, and the registered trigger and report.
`tests/js/links.test.ts`: `linkHref()` / `displayAddress()` and the kind labels in both languages.
