<?php

declare(strict_types=1);

/**
 * The settings page ships a Phosphor font but only defines the icons it uses (settings-page.css).
 * An icon class without a rule renders as an empty tile, so every icon a settings field group
 * uses must have its rule.
 */
test('every icon used by a settings field group is defined in settings-page.css', function () {
	$root = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/';
	$php  = (string) file_get_contents($root . 'includes/class-skwirrel-wc-sync-admin-dashboard.php');
	$css  = (string) file_get_contents($root . 'assets/settings-page.css');

	preg_match_all('/function render_fieldgroup_\w+\(.*?\n\t\}\n/s', $php, $bodies);
	expect(count($bodies[0]))->toBeGreaterThan(5);

	$used = [];
	foreach ($bodies[0] as $body) {
		preg_match_all('/class="ph (ph-[a-z0-9-]+)"/', $body, $m);
		foreach ($m[1] as $icon) {
			$used[$icon] = true;
		}
	}
	expect(array_keys($used))->toContain('ph-file-image', 'ph-pencil-simple', 'ph-plugs-connected');

	$missing = [];
	foreach (array_keys($used) as $icon) {
		if (!str_contains($css, '.' . $icon . ':before')) {
			$missing[] = $icon;
		}
	}
	expect($missing)->toBe([]);
});

test('the Files tab icons use the codes of the shipped Phosphor font', function () {
	$css = (string) file_get_contents(dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/assets/settings-page.css');
	expect($css)->toContain(".ph-file-image:before { content: '\\ea24'; }");
	expect($css)->toContain(".ph-pencil-simple:before { content: '\\e3b4'; }");
});
