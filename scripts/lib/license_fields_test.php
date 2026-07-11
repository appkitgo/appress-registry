#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/license_fields.php';

assert(resolveLicenseType(['license_type' => 'premium']) === 'premium');
assert(resolveLicenseType(['license_type' => 'free']) === 'free');
assert(resolveLicenseType([]) === 'free', 'missing license_type must default to free');
assert(resolveLicenseType(['license_type' => 'bogus']) === 'free', 'unknown values must default to free');
assert(resolveProductSlug(['product_slug' => 'appress-core'], 'apppress/core') === 'appress-core');
assert(resolveProductSlug([], 'apppress/billing') === 'apppress/billing', 'missing product_slug falls back to package slug');

fwrite(STDOUT, "license_fields_test.php: all assertions passed\n");
