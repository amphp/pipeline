<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\async;
use function Amp\delay;

class MergeTest extends AsyncTestCase
{
    public function getArrays(): array
    {
        return [
            [[\range(1, 3), \range(4, 6)], [1, 4, 2, 5, 3, 6]],
            [[\range(1, 5), \range(6, 8)], [1, 6, 2, 7, 3, 8, 4, 5]],
            [[\range(1, 4), \range(5, 10)], [1, 5, 2, 6, 3, 7, 4, 8, 9, 10]],
        ];
    }

    /**
     * @dataProvider getArrays
     */
    public function testMerge(array $array, array $expected): void
    {
        $pipelines = \array_map(
            fn (array $iterator) => Pipeline::fromIterable($iterator)
                ->tap(fn () => delay(0.01)),
            $array,
        );

        $pipeline = Pipeline::merge($pipelines);

        self::assertSame($expected, $pipeline->toArray());
    }

    /**
     * @dataProvider getArrays
     * @depends      testMerge
     */
    public function testMergeWithConcurrentMap(array $array, array $expected): void
    {
        $mapper = static fn (int $value) => $value * 10;

        $pipelines = \array_map(
            fn (array $iterator) => Pipeline::fromIterable($iterator)
                ->concurrent(3)
                ->map($mapper),
            $array,
        );

        $pipeline = Pipeline::merge($pipelines);

        self::assertSame(\array_map($mapper, $expected), $pipeline->toArray());
    }

    /**
     * @depends testMerge
     */
    public function testMergeWithDelayedYields(): void
    {
        $pipelines = [];
        $values1 = [$this->asyncValue(0.01, 1), $this->asyncValue(0.05, 2), $this->asyncValue(0.07, 3)];
        $values2 = [$this->asyncValue(0.02, 4), $this->asyncValue(0.04, 5), $this->asyncValue(0.06, 6)];

        $pipelines[] = Pipeline::fromIterable(function () use ($values1) {
            foreach ($values1 as $value) {
                yield $value->await();
            }
        });

        $pipelines[] = Pipeline::fromIterable(function () use ($values2) {
            foreach ($values2 as $value) {
                yield $value->await();
            }
        });

        $pipeline = Pipeline::merge($pipelines);

        self::assertSame([1, 4, 5, 2, 6, 3], $pipeline->toArray());
    }

    /**
     * @depends testMerge
     */
    public function testDisposedMerge(): void
    {
        $pipelines = [];

        $pipelines[] = Pipeline::fromIterable([1, 2, 3, 4, 5])->tap(fn () => delay(0.1));
        $pipelines[] = Pipeline::fromIterable([6, 7, 8, 9, 10])->tap(fn () => delay(0.1));

        $iterator = Pipeline::merge($pipelines)->getIterator();

        $this->expectException(DisposedException::class);
        $this->setTimeout(0.3);

        while ($iterator->continue()) {
            if ($iterator->getValue() === 7) {
                $iterator->dispose();
            }
        }
    }

    /**
     * @depends testMerge
     */
    public function testMergeWithFailedPipeline(): void
    {
        $exception = new TestException();
        $generator = Pipeline::fromIterable(function () use ($exception) {
            yield 1; // Emit once before failing.
            throw $exception;
        });

        $pipeline = Pipeline::merge([$generator, Pipeline::fromIterable(\range(1, 5))]);

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

        Pipeline::merge([1]);
    }

    private function asyncValue(float $delay, mixed $value): Future
    {
        return async(static function () use ($delay, $value): mixed {
            delay($delay);
            return $value;
        });
    }
}
