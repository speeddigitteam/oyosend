# Notes

Module key `notes`, category Productivity, free offer, depends on `core` only. A small first-party app for short
notes that a member can pin and link to the shared contacts and companies in `Core\Contacts`. It follows the
standard module shape in `docs/MODULES.md`; nothing here is a new pattern.

## 1. What it is

- One note per row (`notes` table): optional `title` and `body` (at least one required), `is_pinned`, an optional
  `color` (a contrast-safe token, null = default), optional `contact_id` / `company_id`, and the record-owner
  columns (`owner_id`, `department_id`, `branch_id`).
- A note **belongs to the member who wrote it** (`App\Core\Access\Concerns\HasRecordOwner`). Reading and changing a
  note honour the `own` / `department` / `branch` / `all` scope of its permission through `RecordAccess`.
- Customers are the shared identity, never a notes-owned table: a note references a `Contact` / `Company` by id and
  reads them through the core models (section 5b of `docs/MODULES.md`).

## 2. Permissions

Both scopable (`docs/MODULES.md` section 5a):

| Name | Group | What it allows |
|---|---|---|
| `notes.notes.view` | `notes` | See notes within the granted record scope |
| `notes.notes.manage` | `notes` | Create, edit, pin and delete notes; reassign the owner (needs the `all` scope) |

## 3. Routes

Workspace host, group `['auth:web', 'auth.session', 'member', 'subscribed']` + `entitled:notes`:

| Route name | Method | Permission |
|---|---|---|
| `notes.index` | GET | `notes.notes.view` |
| `notes.store` | POST | `notes.notes.manage` |
| `notes.update` | PUT | `notes.notes.manage` |
| `notes.destroy` | DELETE | `notes.notes.manage` |

The controller is thin; writes go through `Actions\NoteManager`, which audits each change
(`notes.note.created|updated|deleted`) inside the transaction and dispatches `NoteCreated` after commit.

## 4. What it publishes to other apps

- **Automation trigger** `notes.note.created` (`Automation\Triggers\NoteCreatedTrigger`, event
  `Events\NoteCreated`). Notes registers no automation action: nothing in a note should be changed without a person.
- **Report provider** `notes.by_author` (`Reporting\NotesByAuthorReport`): notes created in a period by their
  author, with how many are pinned, narrowed by `RecordAccess`. Tagged in `NotesServiceProvider::register()`.
- **Customer 360 section** `notes.notes` (`CustomerRecords\NotesSection`, ADR 0020): the notes attached to one
  contact, with an "Add note" action for a member who may manage them.

## 5. Frontend

One page, `resources/js/Modules/Notes/Pages/Index.vue` (`Inertia::render('Modules/Notes/Pages/Index')`): a
Google-Keep-style board — an inline "take a note" composer, a pinned section above the others, a responsive
masonry card grid (CSS `columns`), per-note colour (the contrast-safe `*-soft` tokens only), quick pin/delete on
card hover and a labelled edit modal. Search filters live over the server. Strings live in the `notes`
namespace (`resources/js/Core/i18n/locales/{en,bn}/notes.ts`); catalogue labels (module, group, permissions,
events, trigger) are in `catalogue.ts` and `automation.ts`, and the sidebar label is `shell.nav.notes`.

## 6. Tests

`tests/Feature/Notes/NotesTest.php`: tenant isolation for the model, cross-workspace 404 on the id routes,
not-entitled 404, create/edit/pin/delete, the record scope, and the registered trigger / report / customer section.
