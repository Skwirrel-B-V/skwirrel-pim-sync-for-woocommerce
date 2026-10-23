<?php

declare(strict_types=1);

test('the content language is added to the requested languages when missing', function () {
	expect(Skwirrel_WC_Sync_Service::merge_content_language(['nl-NL', 'nl'], 'fr-FR'))->toBe(['nl-NL', 'nl', 'fr-FR']);
});

test('a content language that is already requested is not added again, whatever its case', function () {
	expect(Skwirrel_WC_Sync_Service::merge_content_language(['nl-NL', 'nl'], 'NL'))->toBe(['nl-NL', 'nl']);
});

test('an empty content language changes nothing', function () {
	expect(Skwirrel_WC_Sync_Service::merge_content_language(['nl'], '  '))->toBe(['nl']);
});
