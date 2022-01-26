<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class SortedTest extends AsyncTestCase
{
    public function testEmpty(): void
    {
        self::assertSame([], Pipeline::fromIterable([])->sorted()->toArray());
    }

    public function testOne(): void
    {
        self::assertSame([1], Pipeline::fromIterable([1])->sorted()->toArray());
    }

    public function testTwoA(): void
    {
        self::assertSame([1, 2], Pipeline::fromIterable([1, 2])->sorted()->toArray());
    }

    public function testTwoB(): void
    {
        self::assertSame([1, 2], Pipeline::fromIterable([2, 1])->sorted()->toArray());
    }

    public function testMultiple(): void
    {
        $values = [1, 2, 1, 5, 6, -1, 4, 6, 3, 2, 3];
        \shuffle($values);

        self::assertSame(
            [-1, 1, 1, 2, 2, 3, 3, 4, 5, 6, 6],
            Pipeline::fromIterable($values)->sorted()->toArray()
        );
    }
}
