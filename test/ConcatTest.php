<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;

class ConcatTest extends AsyncTestCase
{
    public function getArrays(): array
    {
        return [
            [[\range(1, 3), \range(4, 6)], \range(1, 6)],
            [[\range(1, 5), \range(6, 8)], \range(1, 8)],
            [[\range(1, 4), \range(5, 10)], \range(1, 10)],
        ];
    }

    /**
     * @dataProvider getArrays
     *
     * @param array $array
     * @param array $expected
     */
    public function testConcat(array $array, array $expected): void
    {
        $pipelines = \array_map(static function (iterable $iterable): Pipeline\Pipeline {
            return Pipeline\fromIterable($iterable);
        }, $array);

        $pipeline = Pipeline\concat($pipelines);

        foreach ($pipeline as $value) {
            self::assertSame(\array_shift($expected), $value);
        }
    }

    /**
     * @depends testConcat
     */
    public function testConcatWithFailedPipeline(): void
    {
        $exception = new TestException;
        $expected = \range(1, 6);
        $generator = fromIterable(static function () use ($exception) {
            yield 6; // Emit once before failing.
            throw $exception;
        });

        $pipeline = (Pipeline\concat([
            Pipeline\fromIterable(\range(1, 5)),
            $generator,
            Pipeline\fromIterable(\range(7, 10)),
        ]));

        try {
            foreach ($pipeline as $value) {
                self::assertSame(\array_shift($expected), $value);
            }

            self::fail("The exception used to fail the pipeline should be thrown from continue()");
        } catch (TestException $reason) {
            self::assertSame($exception, $reason);
        }

        self::assertEmpty($expected);
    }

    public function testNonPipeline(): void
    {
        $this->expectException(\TypeError::class);

        /** @noinspection PhpParamsInspection */
        Pipeline\concat([1]);
    }
}
