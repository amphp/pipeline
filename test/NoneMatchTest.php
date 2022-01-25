<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class NoneMatchTest extends AsyncTestCase
{
    public function test(): void
    {
        self::assertFalse(Pipeline::fromIterable([1, 2, 3])->noneMatch(fn ($value) => \is_int($value)));
        self::assertTrue(Pipeline::fromIterable([1, 2, 3])->noneMatch(fn ($value) => \is_string($value)));
        self::assertFalse(Pipeline::fromIterable(['', 1])->noneMatch(fn ($value) => \is_string($value)));
        self::assertFalse(Pipeline::fromIterable([1, ''])->noneMatch(fn ($value) => \is_string($value)));
        self::assertTrue(Pipeline::fromIterable([])->noneMatch(fn ($value) => \is_string($value)));
    }
}
