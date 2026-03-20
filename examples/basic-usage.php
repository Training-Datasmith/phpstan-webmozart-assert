<?php

declare(strict_types=1);

/**
 * Example: How phpstan-webmozart-assert narrows types after assertions.
 *
 * Install:
 *   composer require --dev phpstan/phpstan-webmozart-assert
 *
 * Auto-loaded via phpstan/extension-installer.
 * Without the extension, PHPStan cannot understand that Assert::*() narrows types.
 */

use Webmozart\Assert\Assert;

// --- Basic type narrowing ---

function processId(mixed $value): int
{
    Assert::integer($value);
    // PHPStan now knows $value is int — no "mixed cannot be used as int" error.
    return $value;
}

function requireNonEmpty(mixed $value): string
{
    Assert::stringNotEmpty($value);
    // PHPStan knows $value is non-empty-string.
    return $value;
}


// --- Instance of narrowing ---

function formatDate(mixed $date): string
{
    Assert::isInstanceOf($date, \DateTimeInterface::class);
    // PHPStan knows $date is DateTimeInterface.
    return $date->format('Y-m-d');
}


// --- Null-or prefix ---

function optionalString(mixed $value): ?string
{
    Assert::nullOrString($value);
    // PHPStan knows $value is string|null.
    return $value;
}


// --- All prefix (array elements) ---

function processStrings(mixed $values): void
{
    Assert::allString($values);
    // PHPStan knows $values is array<string> (all elements are strings).
    foreach ($values as $v) {
        echo strtoupper($v); // No "argument of type mixed" error.
    }
}


// --- Range checks ---

function requirePositive(mixed $n): int
{
    Assert::integer($n);
    Assert::greaterThan($n, 0);
    // PHPStan knows $n is int.
    return $n;
}
