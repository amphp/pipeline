<?php

namespace Amp\Pipeline;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use function Amp\async;
use function Amp\delay;

class ZipTest extends AsyncTestCase
{
    public function getArrays(): array
    {
        return [
            [[\range(1, 3), \range(4, 6)], [[1, 4], [2, 5], [3, 6]]],
            [[\range(1, 5), \range(6, 8)], [[1, 6], [2, 7], [3, 8]]],
            [[\range(1, 8), \range(5, 9)], [[1, 5], [2, 6], [3, 7], [4, 8], [5, 9]]],
        ];
    }

    /**
     * @dataProvider getArrays
     *
     * @param array $array
     * @param array $expected
     */
    public function testZip(array $array, array $expected): void
    {
        $pipelines = \array_map(static function (array $iterator): Pipeline\Pipeline {
            return Pipeline\fromIterable($iterator);
        }, $array);

        $pipeline = Pipeline\zip($pipelines);

        self::assertSame($expected, $pipeline->toArray());
    }

    /**
     * @depends testZip
     */
    public function testZipWithDelayedYields(): void
    {
        $pipelines = [];
        $values1 = [
            $this->asyncValue(0.01, 1),
            $this->asyncValue(0.05, 2),
            $this->asyncValue(0.07, 3),
            $this->asyncValue(0.1, 4),
        ];
        $values2 = [$this->asyncValue(0.02, 4), $this->asyncValue(0.04, 5), $this->asyncValue(0.06, 6)];
        $expected = [['one' => 1, 'two' => 4], ['one' => 2, 'two' => 5], ['one' => 3, 'two' => 6]];

        $pipelines['one'] = fromIterable(function () use ($values1) {
            foreach ($values1 as $value) {
                yield $value->await();
            }
        });

        $pipelines['two'] = fromIterable(function () use ($values2) {
            foreach ($values2 as $value) {
                yield $value->await();
            }
        });

        $pipeline = Pipeline\zip($pipelines);

        self::assertSame($expected, $pipeline->toArray());
    }

    /**
     * @depends testZip
     */
    public function testDisposedZip(): void
    {
        $pipelines = [];

        $pipelines[] = Pipeline\fromIterable([1, 2, 3, 4, 5])->tap(fn () => delay(0.1));
        $pipelines[] = Pipeline\fromIterable([6, 7, 8, 9, 10])->tap(fn () => delay(0.1));

        $pipeline = Pipeline\zip($pipelines)->getIterator();

        $this->expectException(DisposedException::class);
        $this->setTimeout(0.3);

        while ($pipeline->continue()) {
            if ($pipeline->get() === [2, 7]) {
                $pipeline->dispose();
            }
        }
    }

    /**
     * @depends testZip
     */
    public function testZipWithFailedPipeline(): void
    {
        $exception = new TestException;
        $generator = fromIterable(static function () use ($exception) {
            yield 1; // Emit once before failing.
            throw $exception;
        });

        $pipeline = Pipeline\zip([$generator, Pipeline\fromIterable(\range(1, 5))]);

        try {
            $pipeline->forEach(fn () => null);
            self::fail("The exception used to fail the pipeline should be thrown from continue()");
        } catch (TestException $reason) {
            self::assertSame($exception, $reason);
        }
    }

    public function testNonPipeline(): void
    {
        $this->expectException(\TypeError::class);

        Pipeline\zip([1]);
    }

    private function asyncValue(float $delay, mixed $value): Future
    {
        return async(static function () use ($delay, $value): mixed {
            delay($delay);
            return $value;
        });
    }
}
