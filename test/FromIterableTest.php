<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline;
use function Amp\delay;

class FromIterableTest extends AsyncTestCase
{
    private const TIMEOUT = 0.1;

    public function testTraversable(): void
    {
        $expected = \range(1, 4);
        $generator = (static function () {
            foreach (\range(1, 4) as $value) {
                yield $value;
            }
        })();

        $pipeline = Pipeline\fromIterable($generator);

        while (null !== $value = $pipeline->continue()) {
            self::assertSame(\array_shift($expected), $value);
        }

        self::assertEmpty($expected);
    }

    /**
     * @dataProvider provideInvalidIteratorArguments
     */
    public function testInvalid($arg): void
    {
        $this->expectException(\TypeError::class);

        Pipeline\fromIterable($arg);
    }

    public function provideInvalidIteratorArguments(): array
    {
        return [
            [null],
            [new \stdClass],
            [32],
            [false],
            [true],
            ["string"],
        ];
    }

    public function testInterval(): void
    {
        $count = 3;
        $pipeline = Pipeline\fromIterable(\range(1, $count), self::TIMEOUT);

        $i = 0;
        while (null !== $value = $pipeline->continue()) {
            self::assertSame(++$i, $value);
        }

        self::assertSame($count, $i);
    }

    /**
     * @depends testInterval
     */
    public function testSlowConsumer(): void
    {
        $count = 5;
        $pipeline = Pipeline\fromIterable(\range(1, $count), self::TIMEOUT);

        for ($i = 0; $value = $pipeline->continue(); ++$i) {
            delay(self::TIMEOUT * 2);
        }

        self::assertSame($count, $i);
    }
}
