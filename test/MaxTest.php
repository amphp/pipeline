<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class MaxTest extends AsyncTestCase
{
    public function testEmpty(): void
    {
        self::assertNull(Pipeline::fromIterable([])->max($this->createCallback(0)));
    }

    public function testOne(): void
    {
        self::assertSame(1, Pipeline::fromIterable([1])->max($this->createCallback(0)));
    }

    public function testTwoA(): void
    {
        self::assertSame(2, Pipeline::fromIterable([1, 2])->max(fn ($a, $b) => $a <=> $b));
    }

    public function testTwoB(): void
    {
        self::assertSame(2, Pipeline::fromIterable([2, 1])->max(fn ($a, $b) => $a <=> $b));
    }

    public function testMultiple(): void
    {
        $values = [1, 2, 1, 5, 6, -1, 4, 6, 3, 2, 3];
        \shuffle($values);

        self::assertSame(6, Pipeline::fromIterable($values)->max(fn ($a, $b) => $a <=> $b));
    }
}
