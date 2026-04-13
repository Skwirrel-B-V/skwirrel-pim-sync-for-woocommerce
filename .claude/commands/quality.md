Run all quality checks on the codebase and fix any issues found.

## Steps

1. Run `vendor/bin/pest` — if tests fail, read the failing test and the code it tests, then fix the code (not the test, unless the test is wrong).

2. Run `vendor/bin/phpstan analyse --memory-limit=2G` — for each error:
   - `missingType.iterableValue` / `missingType.parameter`: add proper PHPDoc `@param` / `@return` type annotations (use `array<string, mixed>` for generic arrays, be more specific when the structure is known)
   - `function.impossibleType` on `is_wp_error()`: these are defensive WP guards, add `// @phpstan-ignore function.impossibleType` inline
   - `argument.type`: fix the actual type mismatch (cast to string, etc.)
   - `property.onlyWritten`: check if the property is used via delegation; if truly unused, add `@phpstan-ignore property.onlyWritten`
   - All other errors: read context and fix the root cause

3. Run `vendor/bin/phpcs` — for auto-fixable issues run `vendor/bin/phpcbf` first. For remaining issues, fix manually.

4. Re-run all three checks to confirm zero errors.

## Rules
- Do NOT generate a PHPStan baseline — fix or annotate every error
- Do NOT lower the PHPStan level (currently 6)
- Do NOT suppress phpcs rules globally
- Keep changes minimal — only fix what's reported
- Run checks from the plugin directory: `wp-content/plugins/skwirrel-pim-sync/`
