# Choose how imported files are named in WordPress

**In one sentence:** Let each site decide whether files imported from Skwirrel keep the file name entered in Skwirrel (optionally with a custom prefix), and offer a button to rename the files already imported.
**Customer-specific:** No
**Written by:** plugin owner, with Claude, on 2026-10-21
**Source:** customer feedback via email, 2026-10-21, from a customer contact

## Problem
Every file the plugin downloads (images, PDFs and other attachments) is saved in WordPress as `skwirrel-<code>-<number>.ext`, whatever name was entered in Skwirrel. There is no setting to change this.

A customer chooses file names in Skwirrel on purpose, for findability (for example `vloerverwarmingsbuis-pert-type-1-komo.jpg`). In their words, a system should not replace that name. They also believed they could choose the file name in Skwirrel, and that renaming in WordPress by hand would be undone by the next sync.

Note for support: the plugin finds existing files by the stored source URL and the Skwirrel attachment ID, not by file name. A manual rename in WordPress does not cause a duplicate on its own.

## Desired outcome
A new "Files" settings tab lets the site owner choose how downloaded files are named, for images and for documents alike. Files already imported can be renamed to match, and old links keep working.

Examples:
- A site owner picks "Take over file names as is". The next sync imports `vloerverwarmingsbuis-pert-type-1-komo.jpg` in WordPress with exactly that name.
- A site owner picks "Custom prefix" and enters `acme-`. A file named `datasheet.pdf` in Skwirrel arrives as `acme-datasheet.pdf`.
- A site owner who changes nothing sees no difference: names stay `skwirrel-<code>-<number>.ext`.
- A site owner presses "Rename existing files", reviews the preview, confirms, and the files imported earlier are renamed. Visitors using the old image links still reach the picture.

## Not part of this
- Cleaning up old files that are no longer attached to a product (later task).
- Moving other media settings (`sync_images`, `image_language`) to the new tab.
- Renaming files that were not imported by the plugin.
- Sites that store media on external storage through an offload plugin.

## Decisions
- Global feature for every site; the customer's question is only the source.
- New settings tab "Files" with three naming modes: A. current naming (default, so existing sites do not change), B. custom prefix followed by the Skwirrel file name, C. take over the Skwirrel file name as is.
- Mode C shows an explanation of the risks next to the option: generic names such as `image.jpg` produce many `-1`, `-2` copies; file names are public and searchable; the Skwirrel file name is no longer guaranteed unique.
- The same naming applies to images and to documents/other attachments.
- Duplicate names get `-1`, `-2` from the standard WordPress behaviour (`wp_unique_filename()`).
- File names are made safe for WordPress but keep their capitals. If Skwirrel gives no usable name or extension, the current naming is used and a warning is logged.
- When a file's content changes but its name stays the same, the new content is saved under a new unique name (standard WordPress behaviour) and replaces the old file on the attachment.
- The plugin stores the imported file name on each attachment. If the file name in Skwirrel is different on a later sync, a new attachment is added and attached to the product. The old one stays behind unattached; cleaning these up is a later task.
- A developer filter lets code adjust the final file name.
- "Rename existing files" supports modes B and C, and only renames files that do not already start with the chosen prefix, so pressing it twice changes nothing the second time.
- In mode C the button renames only attachments that have a stored file name. Others are reported as "skipped, run a sync first"; the next sync stores the name without downloading the file again.
- The button runs in batches in the background, starts with a preview (dry run), shows a backup warning, and is blocked with a notice when an offload plugin is active.
- Old file links are redirected permanently to the new ones; the redirect can be switched off.
- The Files tab holds only the naming mode, the prefix field and the rename button.
- All of this ships in one release, including changelog and translations.

## Open questions
- How should the plugin recognise "a different file name" when the Skwirrel attachment ID stays the same? Today the ID is used to find the existing file, so it would otherwise be treated as unchanged.
- How large can the old-to-new link list get on a big catalogue, and where should it be stored so it stays fast?
- Which offload plugins should be detected, and how?
- Should the customer be told a release date? None is promised in this document.

## Proposed issues
### 1. Files tab with naming modes for new downloads
**Type:** enhancement
**Size suggestion:** M
**Blocked by:** none

A site owner can open a new "Files" tab, choose current naming, custom prefix, or take over file names as is, and newly downloaded images and documents are named accordingly.

Done when:
- [ ] Open Settings, then the "Files" tab: three modes are shown, and "current naming" is selected on an existing site.
- [ ] Choose "take over file names as is", run a sync: a new image from Skwirrel named `vloerverwarmingsbuis-pert-type-1-komo.jpg` appears in the media library with exactly that file name.
- [ ] Choose "custom prefix", enter `acme-`, run a sync: a new PDF named `datasheet.pdf` in Skwirrel appears as `acme-datasheet.pdf`.
- [ ] Two different files with the same name in Skwirrel: the second gets `-1`.
- [ ] Choose "take over as is": the risk explanation is visible next to the option.
- [ ] A developer filter changes the final file name.

Edge cases: no usable file name or extension in Skwirrel (current naming used, warning in the log); capitals and unusual characters in the name; a name that already exists in the media library from a manual upload (gets `-1`, never overwrites); a changed file with the same name (saved under a new unique name and replaces the old file on the attachment); an empty prefix in mode B.

Technical notes: file names are built in four places in `class-skwirrel-wc-sync-media-importer.php` (new image, new file, image content replace, file content replace); route them through one helper. Settings in `skwirrel_wc_sync_settings`; new strings in all 7 locales.

### 2. Remember the imported file name and add a new file when it changes
**Type:** enhancement
**Size suggestion:** M
**Blocked by:** 1

The plugin remembers the file name each attachment was imported with. When the file name in Skwirrel is different on a later sync, the new file is added to the product and the old one is left behind.

Done when:
- [ ] After a sync, each newly imported attachment has the Skwirrel file name stored.
- [ ] Rename the file in Skwirrel and sync: a new attachment with the new name is attached to the product.
- [ ] The old attachment still exists in the media library but is no longer attached to the product.
- [ ] Sync again without changes: no new attachments are added.
- [ ] After a sync on a site with files from before this release, the stored file name is filled in without downloading the files again.

Edge cases: file name missing in Skwirrel (nothing stored, no new file); product images versus documents; the same file used by several products; a file name that differs only in capitals.

Technical notes: matching today uses the URL hash and the Skwirrel attachment ID (`find_valid_existing_attachment()`); see the first open question.

### 3. Preview of renaming existing files
**Type:** enhancement
**Size suggestion:** M
**Blocked by:** 2

On the Files tab, a "Rename existing files" button first shows a preview of which files would be renamed from what to what, without changing anything.

Done when:
- [ ] With mode B and prefix `acme-`, the preview lists imported files whose names do not start with `acme-`, with old and new name.
- [ ] Files that already start with the prefix are not in the list.
- [ ] In mode C, attachments without a stored file name appear as "skipped, run a sync first".
- [ ] A backup warning is shown before the user can continue.
- [ ] With an offload plugin active, the button is disabled and a notice explains why.
- [ ] Nothing in the media library or on disk has changed after viewing the preview.

Edge cases: no files to rename (clear message); thousands of files (the preview is paged or summarised); a new name that already exists on disk (shown with its `-1` name); a file missing on disk (listed as skipped).

### 4. Rename existing files in the background
**Type:** enhancement
**Size suggestion:** L
**Blocked by:** 3

After confirming the preview, the plugin renames the files in batches in the background, with a progress indicator.

Done when:
- [ ] Confirm the preview: progress is shown and the files are renamed, including all generated image sizes.
- [ ] The renamed image still shows correctly on the product page and in the media library.
- [ ] Pressing the button again afterwards finds nothing to rename.
- [ ] A rename that fails halfway is rolled back and logged; the other files carry on.
- [ ] Links to the old file inside page and product content now point to the new file.
- [ ] Every rename is logged, and the old file name is kept on the attachment.

Edge cases: a file missing on disk (skipped and logged); the sync running during renaming; an attachment used on several products; a very large number of files (batches, resumable after an interruption); page content with the link stored in serialized data.

Technical notes: use Action Scheduler like the sync; update the attached-file path, attachment metadata (sizes, original image, scaled version) and the stored URL.

### 5. Old file links keep working
**Type:** enhancement
**Size suggestion:** M
**Blocked by:** 4

Someone opening the old URL of a renamed file is sent permanently to the new URL. The site owner can switch this off.

Done when:
- [ ] After a rename, opening the old image URL redirects to the new URL.
- [ ] The same works for a renamed PDF.
- [ ] Switch the redirects off on the Files tab: the old URL no longer redirects.
- [ ] A URL that was never renamed is not affected.

Edge cases: a very large list of renamed files (see open questions); a file renamed twice (the old link reaches the latest file); sub-size image URLs; a new file later saved under an old name.
