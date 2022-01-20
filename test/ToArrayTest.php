<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline;

class ToArrayTest extends AsyncTestCase
{
    public function testNonEmpty(): void
    {
        $pipeline = Pipeline\fromIterable(["abc", "foo", "bar"]);
        self::assertSame(["abc", "foo", "bar"], $pipeline->toArray());
    }

    public function testEmpty(): void
    {
        $pipeline = Pipeline\fromIterable([]);
        self::assertSame([], $pipeline->toArray());
    }
}
