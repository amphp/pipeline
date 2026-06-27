<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\delay;

class ApplyTest extends AsyncTestCase
{
    public function provideEmissionScenarios(): \Generator
    {
        yield 'expand-each-value-into-multiple' => [
            [1, 2, 3],
            static fn (int $value) => Emission::of([$value, $value * 10]),
            [1, 10, 2, 20, 3, 30],
        ];

        yield 'filter-via-empty-emission' => [
            [1, 2, 3, 4, 5],
            static fn (int $value) => Emission::of($value % 2 ? [$value] : []),
            [1, 3, 5],
        ];

        yield 'one-to-one-mapping' => [
            [1, 2, 3],
            static fn (int $value) => Emission::of([$value + 1]),
            [2, 3, 4],
        ];

        yield 'end-emits-remaining-then-stops' => [
            [1, 2, 3, 4, 5],
            static fn (int $value) => $value === 3 ? Emission::end([$value]) : Emission::of([$value]),
            [1, 2, 3],
        ];

        yield 'end-with-empty-stops-immediately' => [
            [1, 2, 3, 4, 5],
            static fn (int $value) => $value === 3 ? Emission::end() : Emission::of([$value]),
            [1, 2],
        ];

        yield 'final-emission-on-first-value' => [
            [1, 2, 3],
            static fn (int $value) => Emission::end([$value]),
            [1],
        ];

        yield 'generator-emission' => [
            [1, 2],
            static function (int $value): Emission {
                return Emission::of((static function () use ($value): \Generator {
                    yield $value;
                    yield $value + 100;
                })());
            },
            [1, 101, 2, 102],
        ];
    }

    /**
     * @dataProvider provideEmissionScenarios
     *
     * @param list<int> $values
     * @param \Closure(int, int):Emission $applicator
     * @param list<int> $expected
     */
    public function testApply(array $values, \Closure $applicator, array $expected): void
    {
        $result = Pipeline::fromIterable($values)
            ->apply($applicator)
            ->toArray();

        self::assertSame($expected, $result);
    }

    /**
     * @dataProvider provideEmissionScenarios
     *
     * @param list<int> $values
     * @param \Closure(int, int):Emission $applicator
     * @param list<int> $expected
     */
    public function testConcurrent(array $values, \Closure $applicator, array $expected): void
    {
        $result = Pipeline::fromIterable($values)
            ->concurrent(3)
            ->apply($applicator)
            ->toArray();

        self::assertSame($expected, $result);
    }

    public function testPositionPassedToApplicator(): void
    {
        $positions = [];

        Pipeline::fromIterable(['a', 'b', 'c'])
            ->apply(static function (string $value, int $position) use (&$positions): Emission {
                $positions[] = $position;

                return Emission::of([$value]);
            })
            ->toArray();

        self::assertSame([0, 1, 2], $positions);
    }

    public function testEndStopsConsumingSource(): void
    {
        $invocationCount = 0;

        $source = Pipeline::fromIterable(function () use (&$invocationCount): \Generator {
            foreach (\range(1, 5) as $value) {
                ++$invocationCount;
                yield $value;
            }
        });

        $result = $source
            ->apply(static fn (int $value) => $value === 2 ? Emission::end([$value]) : Emission::of([$value]))
            ->toArray();

        self::assertSame([1, 2], $result);

        // Eager consumption pulls in next value.
        self::assertSame(\count($result) + 1, $invocationCount);
    }

    public function testConcurrentOrdered(): void
    {
        $values = Pipeline::fromIterable(\range(1, 9))
            ->concurrent(4)
            ->apply(static function (int $value): Emission {
                delay(0.01);

                return Emission::of([$value + 1]);
            })
            ->toArray();

        self::assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $values);
    }

    public function testUnorderedConcurrentEnd(): void
    {
        $size = 50;
        $concurrency = 4;

        // The final emission completes the underlying queue; with multiple unordered
        // coroutines this must not complete the queue more than once.
        $values = Pipeline::fromIterable(\range(1, 100))
            ->concurrent($concurrency)
            ->unordered()
            ->apply(static function (int $value) use ($size): Emission {
                static $i = 0;

                if (++$i < $size) {
                    return Emission::of([$value]);
                }

                return Emission::end([$value]);
            })
            ->toArray();

        // Consumption stops early once the final emission is reached. Concurrent coroutines may
        // emit a few values past the threshold before the queue completes, but never all 100.
        self::assertGreaterThanOrEqual($size, \count($values));
        self::assertLessThan($size + $concurrency, \count($values));
    }

    public function testApplicatorThrows(): void
    {
        $exception = new TestException();

        $iterator = Pipeline::fromIterable([1, 2, 3])
            ->apply(fn () => throw $exception)
            ->getIterator();

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testPipelineFails(): void
    {
        $exception = new TestException();
        $source = new Queue;

        $iterator = $source->pipe()
            ->apply(static fn (mixed $value) => Emission::of([$value]))
            ->getIterator();

        $source->error($exception);

        $this->expectExceptionObject($exception);

        $iterator->continue();
    }

    public function testConsumptionAlreadyStarted(): void
    {
        $pipeline = Pipeline::fromIterable([1, 2, 3]);
        $pipeline->getIterator();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pipeline consumption has already been started');

        $pipeline->apply(static fn (int $value) => Emission::of([$value]));
    }
}
