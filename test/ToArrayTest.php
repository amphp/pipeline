<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

class ToArrayTest extends AsyncTestCase
{
    public function testNonEmpty(): void
    {
        $pipeline = Pipeline::fromIterable(["abc", "foo", "bar"]);
        self::assertSame(["abc", "foo", "bar"], $pipeline->toArray());
    }

    public function testEmpty(): void
    {
        $pipeline = Pipeline::fromIterable([]);
        self::assertSame([], $pipeline->toArray());
    }
}
