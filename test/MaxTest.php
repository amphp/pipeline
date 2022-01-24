<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class MaxTest extends AsyncTestCase
{
    public function testEmpty(): void
    {
        self::assertNull(fromIterable([])->max($this->createCallback(0)));
    }

    public function testOne(): void
    {
        self::assertSame(1, fromIterable([1])->max($this->createCallback(0)));
    }

    public function testTwoA(): void
    {
        self::assertSame(2, fromIterable([1, 2])->max(fn ($a, $b) => $a <=> $b));
    }

    public function testTwoB(): void
    {
        self::assertSame(2, fromIterable([2, 1])->max(fn ($a, $b) => $a <=> $b));
    }

    public function testMultiple(): void
    {
        $values = [1, 2, 1, 5, 6, -1, 4, 6, 3, 2, 3];
        \shuffle($values);

        self::assertSame(6, fromIterable($values)->max(fn ($a, $b) => $a <=> $b));
    }
}
