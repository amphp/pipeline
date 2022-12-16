<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class AnyMatchTest extends AsyncTestCase
{
    public function test(): void
    {
        self::assertTrue(Pipeline::fromIterable([1, 2, 3])->anyMatch(fn ($value) => \is_int($value)));
        self::assertFalse(Pipeline::fromIterable([1, 2, 3])->anyMatch(fn ($value) => \is_string($value)));
        self::assertTrue(Pipeline::fromIterable(['', 1])->anyMatch(fn ($value) => \is_string($value)));
        self::assertTrue(Pipeline::fromIterable([1, ''])->anyMatch(fn ($value) => \is_string($value)));
        self::assertFalse(Pipeline::fromIterable([])->anyMatch(fn ($value) => \is_string($value)));
    }
}
