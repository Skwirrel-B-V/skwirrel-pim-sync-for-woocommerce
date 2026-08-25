# Story 6.5: Document links show a readable name in the shop's language

Status: ready-for-dev

Epic: 6 — Stock and product content driven from Skwirrel data
FR: FR-23 · NFR: NFR-9 (non-destructive field mapping)
Independent of 6.1's resolver — may land at any point in the epic order.

## Story

As a shop visitor,
I want a product's documents listed by their real names in my language,
so that I can tell a mounting instruction from a declaration of performance without opening both.

## Acceptance Criteria

1. **AC1 — exact language match.** Given a document attachment whose `_attachment_translations` contains an entry for the configured `image_language`, when the product syncs, then `$doc['name']` is that entry's `product_attachment_title` and the frontend documents tab renders it as the link text.
2. **AC2 — prefix match.** Given no exact language match but a translation sharing the two-letter prefix (`nl-BE` configured, `nl` present), when the name resolves, then the prefix match is used — via the same exact → prefix → first-entry chain `get_attachment_meta_for_language()` already applies to images, **not** a second parallel implementation.
3. **AC3 — fallback chain, never nameless (NFR-9).** Given an attachment with no translations, or translations carrying an empty title, when the name resolves, then it falls back in this order: attachment-level `product_attachment_title` → `file_name` → URL basename → the literal `Document`. A document is never rendered nameless.
4. **AC4 — historical rows repair themselves.** Given products synced before this story landed, whose stored `_skwirrel_document_attachments` hold raw filenames, when they are re-synced, then the stored names are refreshed to the resolved titles — no separate migration; the normal delta/full sync repairs them.
5. **AC5 — escaping unchanged.** Given a document title containing HTML or a stray tag, when it renders in the documents tab, the admin meta box and the overridable template, then it is escaped at output (`esc_html`) exactly as today. This story changes *which string is chosen*, never *how it is escaped* — no consumer file is edited.
6. **AC6 — the imported file is unaffected.** Given any document, when it is imported, then the media-library attachment's generated filename and extension are byte-for-byte what they are today. The human title must not reach `import_file()`.
7. **AC7 — no new language axis.** No `document_language` setting is introduced. `image_language` is the language input.
8. **AC8 — gates.** `vendor/bin/pest`, `vendor/bin/phpstan analyse --memory-limit=2G` (level 6) and `vendor/bin/phpcs` all pass. New unit coverage pins AC1, AC2 and every rung of AC3.

## Tasks / Subtasks

- [ ] **Task 1 — Extract the shared language-matching chain** (AC2)
  - [ ] In `class-skwirrel-wc-sync-attachment-handler.php`, pull the exact → prefix → first-entry translation-entry *selection* out of `get_attachment_meta_for_language()` (line ~40) into a new private helper, e.g. `pick_attachment_translation( array $att ): array` returning the winning `_attachment_translations` entry (or `[]`).
  - [ ] Re-implement `get_attachment_meta_for_language()` on top of it so image behaviour is provably identical — same `title`/`description` keys, same `file_name` fallback. Do not change its signature or its callers.
- [ ] **Task 2 — Resolve the document display name** (AC1, AC3, AC6, AC7)
  - [ ] Add `public function resolve_document_name( array $att, string $url ): string` implementing the AC3 chain on top of `pick_attachment_translation()`. Public so it is unit-testable without the network (see Testing below).
  - [ ] `trim()` each candidate and reject empty strings before accepting it — a translation entry that exists with an empty `product_attachment_title` must fall through, not win.
  - [ ] In `get_document_attachments()`, replace **line 262** (`$name = (string) ( $att['file_name'] ?? $att['product_attachment_title'] ?? '' );`) and the basename block below it with a single call to the new resolver.
  - [ ] **Keep a separate `$import_name`** holding `$att['file_name'] ?? ''` and pass *that* to `import_file()` — see the ⚠️ note in Dev Notes. The resolved human name goes only into `$docs[]['name']`.
  - [ ] Read the language from settings the way `get_attachment_meta_for_language()` already does (`get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl'`). Do not start using the `$this->image_language` property — it is `@phpstan-ignore property.onlyWritten` today and changing that is out of scope.
- [ ] **Task 3 — Verify the backfill needs no code** (AC4)
  - [ ] Confirm by reading `compute_sync_signature()` that `__version` is folded into the signature, so the version bump this release carries already busts the change gate and every content hash on the first run after upgrade. Record the finding in Completion Notes. Write **no** migration, **no** upgrade routine, **no** new option.
- [ ] **Task 4 — Unit tests** (AC8)
  - [ ] New `tests/Unit/AttachmentHandlerDocumentNameTest.php` (Pest). Cases: exact match; prefix match (`nl-BE` configured → `nl` entry); first-entry fallback when neither matches; empty translation title falls through to attachment `product_attachment_title`; no translations → `file_name`; nothing at all → URL basename; empty URL path → `Document`; HTML in a title is returned verbatim (escaping is the consumer's job).
  - [ ] Add one case asserting `get_attachment_meta_for_language()` output for images is unchanged by the Task 1 refactor (regression pin).
  - [ ] Set the language per test via `$GLOBALS['_test_options']['skwirrel_wc_sync_settings']` (the bootstrap's `get_option` override, `tests/bootstrap.php:105`). Reset it in `afterEach`.
- [ ] **Task 5 — Release hygiene**
  - [ ] Bump the version via `/release` — never by hand. Add the CHANGELOG.md + readme.txt entries.
  - [ ] No new translatable strings are expected. The `Document` last-resort literal is untranslated today; leave it that way so the 7 locales need no regeneration. If you do add a string, the `.pot` and all 7 `.po`/`.mo` must be regenerated.

## Dev Notes

### The defect, precisely

`plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-attachment-handler.php:262`

```php
$name = (string) ( $att['file_name'] ?? $att['product_attachment_title'] ?? '' );
```

Raw filename first; `_attachment_translations` never consulted. Everything downstream is correct — the
wrong string is chosen at this one line.

### ⚠️ The trap that will bite you (AC6)

`$name` at line 262 is **not just the display name** — it is also passed to
`Skwirrel_WC_Sync_Media_Importer::import_file( $url, $name, ... )`, where it is used *only* to derive the
stored file's extension (`class-skwirrel-wc-sync-media-importer.php:200-205`):

```php
$basename = $name ? $name : ( $path ? basename( $path ) : '' );
$ext      = $basename ? pathinfo( $basename, PATHINFO_EXTENSION ) : '';
if ( ! preg_match( '/^[a-z0-9]{2,5}$/i', $ext ) ) { $ext = 'pdf'; }
```

A human title (`"Montagehandleiding"`) has no extension, so the regex fails and **every document would be
stored as `.pdf`** — silently corrupting `.dwg`, `.xlsx`, `.zip` attachments. Naively replacing line 262
ships that bug. Keep `file_name` flowing to `import_file()`; route the resolved title only into
`$docs[]['name']`.

(`import_file()` does not use `$name` for `post_title` — the attachment title is the generated
`skwirrel-{hash}-{time}.{ext}` filename. So the media library is unaffected either way.)

### Reuse, don't duplicate (AC2)

`get_attachment_meta_for_language()` (same class, line ~40) already implements exact → prefix →
first-entry over `_attachment_translations`. It is `private`, reads `image_language` straight from the
settings option, and returns `[ 'title', 'description' ]` with a **`file_name` fallback baked in**.

That baked-in fallback is why you cannot simply call it for documents: AC3 needs
`product_attachment_title` (attachment level) to sit *between* the translation and `file_name`, and the
existing helper collapses that. Extract the entry-*selection* half (Task 1) and let both callers layer
their own fallback tail on top. That satisfies "not a second parallel implementation" without bending the
image path.

### Do not touch the consumers (AC5)

All three already `esc_html()` the name — none need changing:

- `class-skwirrel-wc-sync-product-documents.php:82` — frontend tab
- `class-skwirrel-wc-sync-product-documents.php:125` — admin meta box
- `templates/single-product/tabs/skwirrel-documents.php:30` — overridable template

Note `get_documents_for_product()` / `get_documents()` **drop any doc with an empty `name`** — which is
exactly why the AC3 fallback chain matters: a nameless document does not render badly, it vanishes.

### Why AC4 needs no migration

`_skwirrel_document_attachments` is rewritten wholesale on every upsert
(`class-skwirrel-wc-sync-product-upserter.php:477-478` simple, `:2485-2486` variation), so any product
that reaches the commit step gets fresh names. The only question is whether the change gate skips it —
and it cannot, on the first run after this release:

- `compute_sync_signature()` (`class-skwirrel-wc-sync-service.php:2369`) folds `__version` in, and every
  release bumps `SKWIRREL_WC_SYNC_VERSION`. Signature differs → gate disabled → full reprocess, and a
  requested delta is promoted to a full pass.
- The same `sync_sig` seeds `content_hash()` (`product-upserter.php:188`), so every stored
  `_skwirrel_content_hash` mismatches too, even in `enforce` mode.

Record this in Completion Notes rather than adding defensive code.

### Explicitly out of scope

- `get_downloadable_files()` (line ~216) also uses `$att['file_name'] ?? 'Download'` for the **WooCommerce
  downloadable-file** name. FR-23 covers the documents tab only. Leave it. If it should change, that is a
  new FR, not a silent addition here.
- A `document_language` setting (AC7). `image_language` is the axis.
- The `$this->image_language` constructor property / its `@phpstan-ignore property.onlyWritten`.

### Testing

- Unit only. `get_document_attachments()` itself calls `import_file()` → network + filesystem, so it is
  not unit-testable; that is why the resolver is extracted as a **public** pure method (precedent:
  `Skwirrel_WC_Sync_Media_Importer::is_image_attachment_type()` is public for the same reason).
- Pest style: `test()` / `beforeEach()` / `expect()`, `with()` for datasets. No class-based PHPUnit.
- The handler is already loaded by `tests/bootstrap.php:546`.
- Language injection: `$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [ 'image_language' => 'nl-BE' ];`
- Do not weaken the image regression pin to make a refactor pass — if image output changes, the refactor
  is wrong.

### Project Structure Notes

- Single file changed under `plugin/skwirrel-pim-sync/includes/`; one new file under `tests/Unit/`.
- Repo root ≠ plugin: shippable code only under `plugin/skwirrel-pim-sync/`. Tests stay at repo root.
- PHP 8.3, `declare(strict_types=1)`, WPCS naming — new methods stay `snake_case` on the existing class.
- Type the new methods fully (`array $att`, `string $url`, `: string` / `: array`) — PHPStan level 6 with a
  baseline that must not absorb new findings. Add `@param array<string,mixed>` / `@return` docblocks in the
  style of `build_api_meta()`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 6.5] — ACs and as-built implementation notes
- [Source: _bmad-output/planning-artifacts/epics.md#Chapter 2 — Requirements Inventory] — FR-23, NFR-9
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-attachment-handler.php:40,241-283]
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-media-importer.php:169-246]
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php:2369] — sync signature
- [Source: plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-product-upserter.php:164-188,477,2485]
- [Source: _bmad-output/project-context.md#Testing & Quality Gates]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
