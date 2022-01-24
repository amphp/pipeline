<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class AllMatchTest extends AsyncTestCase
{
    public function test(): void
    {
        self::assertTrue(fromIterable([1, 2, 3])->allMatch(fn ($value) => \is_int($value)));
        self::assertFalse(fromIterable([1, 2, 3])->allMatch(fn ($value) => \is_string($value)));
        self::assertFalse(fromIterable(['', 1])->allMatch(fn ($value) => \is_string($value)));
        self::assertFalse(fromIterable([1, ''])->allMatch(fn ($value) => \is_string($value)));
        self::assertTrue(fromIterable([])->allMatch(fn ($value) => \is_string($value)));
    }
}
