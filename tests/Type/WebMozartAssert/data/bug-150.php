<?php

declare(strict_types=1);

namespace WebmozartAssertBug150;

use function PHPStan\Testing\assertType;

use Webmozart\Assert\Assert;

class Bug150
{
    public function doFoo($data): void
    {
        Assert::isArray($data);
        Assert::keyExists($data, 'sniffs');
        Assert::isArray($data['sniffs']);
        assertType("non-empty-array&hasOffsetValue('sniffs', array<mixed, mixed>)", $data);

        foreach ($data['sniffs'] as $sniffName) {
            Assert::string($sniffName);
            Assert::classExists($sniffName);
            assertType('class-string', $sniffName);
            Assert::implementsInterface($sniffName, SniffInterface::class);
            assertType('class-string<WebmozartAssertBug150\SniffInterface>', $sniffName);
        }
    }

}

interface SniffInterface
{
}
