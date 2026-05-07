# Post-release status (after 3.8.1)

Snapshot of where the plugin sits after a sequence of substantive releases
(3.7.0 → 3.8.0 → 3.8.1). Captures what shipped, what's deliberately parked,
and what to actually do next so context isn't lost between sessions.

## What shipped

* **3.7.0** — PHP 8.3 floor, Node 22 LTS floor, CI bumped, 28 class files
  renamed to `class-skwirrel-wc-sync-{slug}.php`, bootstrap class extracted
  into its own file, plugin-wide WPCS cleanup.
* **3.8.0** — Stable Skwirrel-media → WP-attachment mapping
  (`_skwirrel_attachment_id`), content-change detection via Skwirrel's
  `file_sha256_checksum`, offload-plugin-safe missing-file guard, lazy
  migration for pre-3.8 attachments. Plus a batch of sync-safety fixes from
  code review: heartbeat mutex against concurrent runs, per-run queue
  isolation (no more global `TRUNCATE`), pagination atomicity (a later-page
  failure no longer reaches purge), multi-selection support in the main
  fetch, cross-sells/upsells clear when the API removes them.
* **3.8.1** — Patch: grouped-products multi-selection. Mirrors the 3.8.0
  main-fetch fix in `Skwirrel_WC_Sync_Product_Upserter::sync_grouped_products_first()`.

## Watch list (next 1-2 weeks)

* Confirm WordPress.org SVN deploys for 3.8.0 and 3.8.1 actually landed
  (check the wp.org listing).
* Monitor support channels — substantial changes have shipped; let them
  bake before piling more on top.
* For sites that run a media-offload plugin (WP Offload Media, S3 Uploads,
  …): communicate the new `mu-plugins/skwirrel-offload-compat.php` drop-in.
  It is *not* bundled in the plugin ZIP. Without it the missing-file guard
  falls back to "clear Skwirrel meta + download fresh" on offloaded
  attachments, which is safe but creates duplicate WP attachments.

## Open tickets (bug-flavoured)

### Action Scheduler args verification
* **Source:** code review, P2.
* **Claim:** the WP-Cron fallback in `Skwirrel_WC_Sync_Action_Scheduler::enqueue_manual_sync()`
  passes `[ [ 'delta' => false ] ]` while AS calls pass `[ 'delta' => false ]`.
  Reviewer says one of these is wrong. We pushed back: looks like AS
  is the broken half on PHP 8+ named-args semantics, not WP-Cron.
* **Status:** unverified. Need an empirical reproduction in an integration
  test (schedule a manual full-sync, observe whether `$delta` is honoured).
* **Effort:** ~30 min.
* **Trigger to act:** a customer reports "I clicked Sync now and it ran a
  delta instead of a full sync."

### Media removal (P3)
* **Source:** code review, P3.
* **Claim:** when Skwirrel removes an image, the WC product keeps it.
* **Status:** parked. Needs a design pass: how do we distinguish
  Skwirrel-managed media from manually-attached media on the same product?
  The new `_skwirrel_attachment_id` meta gives us the lever, but the
  policy decision (which attachments to remove, when) hasn't been made.
* **Effort:** design + implementation > 1 day.
* **Trigger to act:** a customer reports "I removed images in Skwirrel
  but they still show on the WC product."

### `Skwirrel_WC_Sync_Action_Scheduler::enqueue_manual_sync` already covered above.

## Open backlog (not bug-flavoured)

### Test coverage gaps
The audit during 3.8.0 prep flagged classes without tests. We bug-driven-fixed
the ones with active issues (Etim_Extractor, Custom_Class_Extractor,
Purge_Handler, sync safety). Remaining gaps, in rough priority:

* `Variation_Attributes_Fix` — recently touched (`$object → $wc_object`
  PHP 8.2+ keyword fix); no test would have caught a regression.
* `Delete_Protection` — small class but kicks the `force_full_sync` flag,
  which is destructive if it misfires.
* `JsonRpc_Client` — wire format + retry behaviour. Lots of mock work to
  do meaningfully.
* `Category_Sync` and `Attachment_Handler` — integration tests, real WP
  needed to exercise term creation / media linking.

**Recommendation:** don't mass-write these. Add when next touching the
class for an actual change. Tests written retroactively codify current
behaviour without finding bugs; tests written alongside a change earn
their keep.

### Phpstan baseline (179 entries)
* 95% are missing array-shape annotations on external API payloads.
* Cleaning these up requires defining `@phpstan-type` aliases for every
  Skwirrel V1 response shape and propagating through callers.
* No bug-finding value; pure pedantry.
* **Recommendation:** leave as-is.

## Latent gotchas

* **Symfony pin** is on `main` (commit `d44773a`) — `composer.json`
  constrains `symfony/console`/`finder`/`process`/`string` to `^7.4` so
  `composer update` doesn't silently jump to the symfony-8 line that
  requires PHP 8.4. If a future contributor removes this pin, CI will
  break the moment someone runs `composer update`.

## What I would NOT do right now

* **Refactor for refactor's sake.** The codebase is healthy.
* **Add features.** 3.8.0 + 3.8.1 just shipped substantial behavioural
  changes; let them prove themselves before piling on.
* **Mass test-writing.** Tests written without a triggering issue tend
  to lock in arbitrary behaviour rather than catch regressions.
* **Phpstan baseline cleanup.** Already covered above.

## When to come back to this doc

* Before starting the next session, to recall what's parked and why.
* When deciding the next release scope.
* When a customer report maps to one of the open tickets — promote it
  to active and act.
