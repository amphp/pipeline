<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class AnyMatchTest extends AsyncTestCase
{
    public function test(): void
    {
        self::assertTrue(fromIterable([1, 2, 3])->anyMatch(fn ($value) => \is_int($value)));
        self::assertFalse(fromIterable([1, 2, 3])->anyMatch(fn ($value) => \is_string($value)));
        self::assertTrue(fromIterable(['', 1])->anyMatch(fn ($value) => \is_string($value)));
        self::assertTrue(fromIterable([1, ''])->anyMatch(fn ($value) => \is_string($value)));
        self::assertFalse(fromIterable([])->anyMatch(fn ($value) => \is_string($value)));
    }
}
