# Filter products by Skwirrel management in the product overview

**In one sentence:** Add a simple native dropdown to the WooCommerce product overview that shows only products managed in Skwirrel, or only products that are not.
**Customer-specific:** Yes: Conval
**Written by:** plugin owner, with Claude, on 2026-10-21

## Problem
Shop owners who run the Skwirrel sync next to products they made by hand cannot tell the two apart in the product overview (Products → All Products). They have to open each product to see whether it is managed in Skwirrel.

## Desired outcome
The product overview has a dropdown that lets a shop owner see only the products managed in Skwirrel, or only the products that are not, in the same filter bar as the other filters.

Examples:
- A shop owner opens Products → All Products, picks "Managed in Skwirrel" in the dropdown and sees only synced products.
- The same shop owner picks "Not managed in Skwirrel" and sees only the products created by hand, ready to edit in bulk.
- A shop owner searches for "buis", picks "Managed in Skwirrel" and sees only synced products matching the search.

## Not part of this
- A badge, column or other marker in the product list.
- Counts on the filter, or extra link tabs above the list.
- A link in the list to open the product in Skwirrel (the product edit screen already has an "Open in Skwirrel" button).
- Any new setting.

## Decisions
- Global code in the plugin; Conval is the customer who asked for it.
- One dropdown in the native filter bar with three choices: All products, Managed in Skwirrel, Not managed in Skwirrel.
- Simplest native form: only this dropdown, nothing custom.
- A product counts as managed in Skwirrel when it has a Skwirrel ID, whether or not it is still present in Skwirrel. Variations follow their parent.
- The filter works together with the existing filters (search, category, stock status, manufacturer dropdown, identifier search), paging and sorting.
- It applies to the normal list views. Support for the Trash view is not required. The counts above the list stay as they are.
- The dropdown appears only on shops that use the sync.

## Open questions
None

## Proposed issues
### 1. [Conval] Filter products by Skwirrel management in the product overview
**Type:** enhancement
**Size suggestion:** S
**Blocked by:** none

A shop owner can pick "Managed in Skwirrel" or "Not managed in Skwirrel" in a dropdown in the product overview and the list shows only those products.

Done when:
- [ ] Open Products → All Products on a shop with the sync set up: a dropdown with "All products", "Managed in Skwirrel" and "Not managed in Skwirrel" is in the filter bar.
- [ ] Pick "Managed in Skwirrel": only products with a Skwirrel ID are listed.
- [ ] Pick "Not managed in Skwirrel": only products without a Skwirrel ID are listed.
- [ ] Combine the dropdown with a search term and with the category filter: the list matches both.
- [ ] Go to page 2 or sort by price: the filter stays active.
- [ ] On a shop without the sync set up, the dropdown is not shown.
- [ ] New strings are translated in all 7 locales; changelog and readme are updated.

Edge cases: a product that is no longer in Skwirrel but still has a Skwirrel ID counts as managed; a variable product's variations are not listed separately; the Trash view is not required to support the filter.
