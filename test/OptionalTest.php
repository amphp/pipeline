<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;

final class OptionalTest extends AsyncTestCase
{
    public function testEmpty(): void
    {
        $empty = Optional::empty();
        self::assertNull($empty->get());

        $empty->map($this->createCallback(0));
        $empty->filter($this->createCallback(0));
    }

    public function testOfNull(): void
    {
        $empty = Optional::of(null);
        self::assertNull($empty->get());

        $empty->map($this->createCallback(0));
        $empty->filter($this->createCallback(0));
    }

    public function testOfPresent(): void
    {
        $empty = Optional::of(42);
        self::assertSame(42, $empty->get());

        $empty->map($this->createCallback(1));
        self::assertSame(21, $empty->map(fn (int $value) => $value / 2)->get());

        $empty->filter($this->createCallback(1));
        self::assertSame(42, $empty->filter(fn (int $value) => true)->get());
        self::assertNull($empty->filter(fn (int $value) => false)->get());
    }
}
