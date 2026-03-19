Generate Pest PHP tests for a class or method in the Skwirrel PIM sync plugin.

## Input

The user specifies: $ARGUMENTS (class name, method name, or file path)

If no argument given, ask which class/method to test.

## Steps

1. **Read the source**: Load the class file and understand what the method does — inputs, outputs, side effects, edge cases.

2. **Read existing tests**: Check `tests/Unit/` for existing test files for this class. Read `tests/bootstrap.php` and `tests/Pest.php` to understand the test setup (WP/WC stubs, etc.).

3. **Generate tests** following these conventions (from `.claude/rules/testing.md`):
   - Use Pest `test()` function syntax, not class-based PHPUnit
   - Use `beforeEach()` for shared setup
   - Use `expect()` API for assertions
   - File naming: `{ClassName}Test.php`
   - Test naming: `test('descriptive name', function () { ... })`
   - Use `dataset()` / `with()` for multiple input variations
   - Place in `tests/Unit/`

4. **Cover these scenarios**:
   - Happy path with typical input
   - Edge cases: empty arrays, null values, missing keys
   - Fallback chains (this codebase uses many `$data['key'] ?? $data['alt_key'] ?? ''`)
   - Error conditions where applicable

5. **Run `vendor/bin/pest`** to verify all tests pass (new and existing).

## Rules
- Tests must be self-contained — no database, no HTTP, no WP/WC runtime
- Use the stubs from `tests/bootstrap.php` for WP/WC functions
- If a method has too many dependencies to unit test, suggest which part to extract and test
- Do NOT modify source code — only create/modify test files
