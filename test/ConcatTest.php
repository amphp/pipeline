<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline\Internal\ConcurrentArrayIterator;
use Amp\Pipeline\Internal\ConcurrentChainedIterator;
use function Amp\async;
use function Amp\Future\awaitFirst;

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
     */
    public function testConcatIterator(array $array, array $expected): void
    {
        $iterators = \array_map(fn (array $array) => new ConcurrentArrayIterator($array), $array);
        $pipeline = new Pipeline(new ConcurrentChainedIterator($iterators));
        self::assertSame($expected, $pipeline->toArray());
    }

    /**
     * @dataProvider getArrays
     */
    public function testConcatPipeline(array $array, array $expected): void
    {
        $pipeline = Pipeline::concat($array);
        self::assertSame($expected, $pipeline->toArray());
    }

    public function testConcurrency(): void
    {
        // We need a slow known-size iterator here, so the second fiber can jump right to the second iterator
        $iterator1 = new ConcurrentDelayedArrayIterator(1, [1]);
        $iterator2 = Pipeline::fromIterable(function () {
            yield 2;
        })->getIterator();

        $iterator = new ConcurrentChainedIterator([$iterator1, $iterator2]);

        $future1 = async(function () use ($iterator) {
            $iterator->continue();
            return $iterator->getValue();
        });
        $future2 = async(function () use ($iterator) {
            $iterator->continue();
            return $iterator->getValue();
        });

        self::assertSame(2, awaitFirst([$future1, $future2]));
    }

    /**
     * @depends testConcatPipeline
     */
    public function testConcatWithFailedPipeline(): void
    {
        $exception = new TestException;
        $expected = \range(1, 6);
        $generator = Pipeline::fromIterable(static function () use ($exception) {
            yield 6; // Emit once before failing.
            throw $exception;
        });

        $pipeline = (Pipeline::concat([
            Pipeline::fromIterable(\range(1, 5)),
            $generator,
            Pipeline::fromIterable(\range(7, 10)),
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

        Pipeline::concat([1]);
    }
}
