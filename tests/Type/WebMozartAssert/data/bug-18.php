<?php

declare(strict_types=1);

namespace Bug18;

use function PHPStan\Testing\assertType;

use Webmozart\Assert\Assert;

class MyThingFactory
{
    public function make(string $thing)
    {
        Assert::implementsInterface($thing, SomeDto::class);

        assertType('class-string<Bug18\SomeDto>', $thing);
    }
}

interface SomeDto
{
}
