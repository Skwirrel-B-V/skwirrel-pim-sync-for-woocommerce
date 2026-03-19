Validate that the plugin's API field usage matches the Skwirrel JSON-RPC API schema.

## What to check

1. **Read the API contract**: Load `ASSUMPTIONS.md` and `CLAUDE.md` for the known API schema. Also read `includes/class-jsonrpc-client.php` for actual API calls made.

2. **Verify field access in mappers**: Read these files and check that every field accessed from `$product` matches the documented schema:
   - `includes/class-product-mapper.php` — field extraction (`$product['field_name']`)
   - `includes/class-attachment-handler.php` — attachment fields
   - `includes/class-etim-extractor.php` — ETIM fields
   - `includes/class-custom-class-extractor.php` — custom class fields
   - `includes/class-category-sync.php` — category fields from `_categories`
   - `includes/class-brand-sync.php` — brand/manufacturer fields

3. **Check for issues**:
   - Fields accessed that don't exist in the schema
   - Fields in the schema that are available but not used (might be useful)
   - Incorrect nesting assumptions (e.g. `$product['_trade_items'][0]['_trade_item_prices']`)
   - Missing null/empty checks on optional fields
   - Type mismatches (expecting string but API returns int, etc.)

4. **Check API call parameters**: Verify that parameters passed to `getProducts`, `getProductsByFilter`, `getGroupedProducts`, and `getCategories` in `class-sync-service.php` match the API spec.

## Output

Report findings as a checklist:
- [ ] Field X accessed but not in schema
- [ ] Field Y available in schema but unused
- [x] Field Z correctly mapped

Do NOT make code changes — this is a read-only audit. Suggest fixes if issues are found.
