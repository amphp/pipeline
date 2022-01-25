<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class AllMatchTest extends AsyncTestCase
{
    public function test(): void
    {
        self::assertTrue(Pipeline::fromIterable([1, 2, 3])->allMatch(fn ($value) => \is_int($value)));
        self::assertFalse(Pipeline::fromIterable([1, 2, 3])->allMatch(fn ($value) => \is_string($value)));
        self::assertFalse(Pipeline::fromIterable(['', 1])->allMatch(fn ($value) => \is_string($value)));
        self::assertFalse(Pipeline::fromIterable([1, ''])->allMatch(fn ($value) => \is_string($value)));
        self::assertTrue(Pipeline::fromIterable([])->allMatch(fn ($value) => \is_string($value)));
    }
}
