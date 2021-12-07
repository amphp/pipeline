<?php

namespace Amp\Pipeline;

use Amp\Future;
use Amp\Sync\Semaphore;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

/**
 * Creates a source that can create any number of pipelines by calling {@see Source::pipe()}. The new pipelines
 * will each emit values identical to that of the given pipeline. The original pipeline is only disposed if all
 * downstream pipelines are disposed.
 *
 * @template TValue
 *
 * @param Pipeline<TValue> $pipeline
 *
 * @return Source<TValue>
 */
function share(Pipeline $pipeline): Source
{
    return new Internal\SharedSource($pipeline);
}

/**
 * Creates a pipeline from the given iterable, emitting each value.
 *
 * @template TValue
 *
 * @param iterable<array-key, TValue> $iterable Elements to emit.
 *
 * @return Pipeline<TValue>
 */
function fromIterable(iterable $iterable): Pipeline
{
    return new AsyncGenerator(static fn () => yield from $iterable);
}

/**
 * Creates a pipeline that emits values emitted from any pipeline in the array of pipelines.
 *
 * @template TValue
 *
 * @param Pipeline<TValue>[] $pipelines
 *
 * @return Pipeline<TValue>
 */
function merge(array $pipelines): Pipeline
{
    $emitter = new Emitter;

    $futures = [];
    foreach ($pipelines as $key => $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf(
                'Argument #1 ($pipelines) must be of type array<%s>, %s given at key %s',
                Pipeline::class,
                \get_debug_type($pipeline),
                $key
            ));
        }

        $futures[] = async(static function () use ($emitter, $pipeline): void {
            foreach ($pipeline as $value) {
                if ($emitter->isComplete()) {
                    return;
                }

                $emitter->yield($value);
            }
        });
    }

    EventLoop::queue(static function () use ($emitter, $futures, $pipelines): void {
        try {
            Future\all($futures);

            $emitter->complete();
        } catch (\Throwable $exception) {
            $emitter->error($exception);
        } finally {
            foreach ($pipelines as $pipeline) {
                $pipeline->dispose();
            }
        }
    });

    return $emitter->pipe();
}

/**
 * Concatenates the given pipelines into a single pipeline, emitting from a single pipeline at a time. The
 * prior pipeline must complete before values are emitted from any subsequent pipelines. Pipelines are concatenated
 * in the order given (iteration order of the array).
 *
 * @template TValue
 *
 * @param Pipeline<TValue>[] $pipelines
 *
 * @return Pipeline<TValue>
 */
function concat(array $pipelines): Pipeline
{
    foreach ($pipelines as $key => $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf(
                'Argument #1 ($pipelines) must be of type array<%s>, %s given at key %s',
                Pipeline::class,
                \get_debug_type($pipeline),
                $key
            ));
        }
    }

    return new AsyncGenerator(static function () use ($pipelines): \Generator {
        try {
            foreach ($pipelines as $pipeline) {
                yield from $pipeline;
            }
        } finally {
            foreach ($pipelines as $pipeline) {
                $pipeline->dispose();
            }
        }
    });
}

/**
 * Combines all given pipelines into one pipeline, emitting an array of values only after each pipeline has emitted a
 * value. The returned pipeline completes when any pipeline completes or errors when any pipeline errors.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @param array<TKey, Pipeline<TValue>> $pipelines
 *
 * @return Pipeline<array<TKey, TValue>>
 */
function zip(array $pipelines): Pipeline
{
    foreach ($pipelines as $key => $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf(
                'Argument #1 ($pipelines) must be of type array<%s>, %s given at key %s',
                Pipeline::class,
                \get_debug_type($pipeline),
                $key
            ));
        }
    }

    return new AsyncGenerator(static function () use ($pipelines): \Generator {
        try {
            $keys = \array_keys($pipelines);
            while (true) {
                $next = Future\all(\array_map(
                    static fn (Pipeline $pipeline) => async(static fn () => $pipeline->continue()),
                    $pipelines
                ));

                if (\in_array(needle: null, haystack: $next, strict: true)) {
                    return;
                }

                // Reconstruct emit array to ensure keys are in same iteration order as pipelines.
                yield \array_map(static fn ($key) => $next[$key], $keys);
            }
        } finally {
            foreach ($pipelines as $pipeline) {
                $pipeline->dispose();
            }
        }
    });
}

/**
 * Concurrently act on a pipeline using the given set of operators. The resulting pipeline will *not* necessarily be
 * in the same order as the source pipeline, however, items are emitted as soon as they are available.
 * Use {@see concurrentOrdered()} if item order matters.
 *
 * @template TValue
 * @template TResult
 *
 * @param Semaphore $semaphore Semaphore limiting the concurrency, e.g. {@see LocalSemaphore}.
 * @param Operator ...$operators Set of operators to act upon each value emitted. See {@see Pipeline::pipe()}.
 *
 * @return Operator<TValue, TResult>
 */
function concurrentUnordered(Semaphore $semaphore, Operator ...$operators): Operator
{
    return new Operator\ConcurrentOperator($semaphore, $operators, false);
}

/**
 * Concurrently act on a pipeline using the given set of operators. The resulting pipeline will maintain the original
 * order of items emitted from the source. Note that items that take a long time to process may then delay the emission
 * of subsequent items that were processed faster. Slow items do not block processing further items, but will prevent
 * emitting subsequent values on the returned pipeline that were previously emitted from the source until the slow
 * item has completed.
 * Use {@see concurrentUnordered()} if order does not matter.
 *
 * @template TValue
 * @template TResult
 *
 * @param Semaphore $semaphore Semaphore limiting the concurrency, e.g. {@see LocalSemaphore}.
 * @param Operator ...$operators Set of operators to act upon each value emitted. See {@see Pipeline::pipe()}.
 *
 * @return Operator<TValue, TResult>
 */
function concurrentOrdered(Semaphore $semaphore, Operator ...$operators): Operator
{
    return new Operator\ConcurrentOperator($semaphore, $operators, true);
}

/**
 * Removes backpressure on the source pipeline.
 *
 * @template TValue
 *
 * @return Operator<TValue, TValue>
 */
function relieve(): Operator
{
    return new Operator\RelieveOperator;
}

/**
 * @template TValue
 * @template TReturn
 *
 * @param \Closure(TValue):TReturn $map
 *
 * @return Operator<TValue, TReturn>
 */
function map(\Closure $map): Operator
{
    return new Operator\MapOperator($map);
}

/**
 * @template TValue
 *
 * @param \Closure(TValue):bool $filter
 *
 * @return Operator<TValue, TValue>
 */
function filter(\Closure $filter): Operator
{
    return new Operator\FilterOperator($filter);
}

/**
 * Postpone (delay) consumption of each value from the source pipeline for the given amount of time.
 *
 * @template TValue
 *
 * @param float $delay
 * @return Operator<TValue, TValue>
 */
function postpone(float $delay): Operator
{
    return postponeWhen(new AsyncGenerator(static function () use ($delay): \Generator {
        while (true) {
            delay($delay);
            yield 0;
        }
    }));
}

/**
 * Values are not consumed from the source pipeline until the $postponeUntil pipeline emits. The values emitted from
 * the returned pipeline are identical to those of the source pipeline.
 * The returned pipeline completes or errors when either the source or $postponeUntil completes or errors.
 *
 * @template TValue
 *
 * @param Pipeline<mixed> $postponeUntil
 * @return Operator<TValue, TValue>
 */
function postponeWhen(Pipeline $postponeUntil): Operator
{
    return new Operator\PostponeUntilOperator($postponeUntil);
}

/**
 * Skip the first X number of items emitted on the pipeline.
 *
 * @template TValue
 *
 * @param int $count
 * @return Operator<TValue, TValue>
 */
function skip(int $count): Operator
{
    return new Operator\SkipOperator($count);
}

/**
 * Skips values emitted on the pipeline until $predicate returns false. All values are emitted afterward without
 * invoking $predicate.
 *
 * @template TValue
 *
 * @param \Closure(TValue):bool $predicate
 * @return Operator<TValue, TValue>
 */
function skipWhile(\Closure $predicate): Operator
{
    return new Operator\SkipWhileOperator($predicate);
}

/**
 * Take only the first X number of items emitted on the pipeline.
 *
 * @template TValue
 *
 * @param int $count
 * @return Operator<TValue, TValue>
 */
function take(int $count): Operator
{
    return new Operator\TakeOperator($count);
}

/**
 * Emit values from the pipeline as until $predicate returns false.
 *
 * @template TValue
 *
 * @param \Closure(TValue):bool $predicate
 * @return Operator<TValue, TValue>
 */
function takeWhile(\Closure $predicate): Operator
{
    return new Operator\TakeWhileOperator($predicate);
}

/**
 * Invokes the given function each time a value is emitted to perform side effects with the value.
 * While this could be accomplished with map, the intention of this operator is to keep those functions pure.
 *
 * @template TValue
 *
 * @param \Closure(TValue):void $tap
 * @return Operator<TValue, TValue>
 */
function tap(\Closure $tap): Operator
{
    return new Operator\TapOperator($tap);
}

/**
 * Invokes the given function when the pipeline completes, either successfully or with an error.
 *
 * @template TValue
 *
 * @param \Closure():void $finally
 * @return Operator<TValue, TValue>
 */
function finalize(\Closure $finally): Operator
{
    return new Operator\FinalizeOperator($finally);
}

/**
 * The last value emitted on the pipeline is emitted only when $sampleWhen emits. If the previous value has already
 * been emitted when $sampleWhen emits, no value is emitted on the returned pipeline.
 *
 * The returned pipeline completes or errors when either the source or $sampleWhen completes or errors.
 *
 * @template TValue
 *
 * @param Pipeline<mixed> $sampleWhen
 * @return Operator<TValue, TValue>
 */
function sampleWhen(Pipeline $sampleWhen): Operator
{
    return new Operator\SampleWhenOperator($sampleWhen);
}

/**
 * @template TValue
 *
 * @param float $period
 * @return Operator<TValue, TValue>
 */
function sampleInterval(float $period): Operator
{
    return sampleWhen(
        (new AsyncGenerator(static function (): \Generator {
            while (true) {
                yield 0;
            }
        }))->pipe(postpone($period))
    );
}

/**
 * @template TValue
 *
 * @param Pipeline<TValue> $pipeline
 * @param \Closure(TValue):void $each
 */
function each(Pipeline $pipeline, \Closure $each): void
{
    foreach ($pipeline as $value) {
        $each($value);
    }
}

/**
 * @template TValue
 * @template TResult
 *
 * @param Pipeline<TValue> $pipeline
 * @param \Closure(TResult, TValue):TResult $accumulator
 * @param TResult $initial
 * @return TResult
 */
function reduce(Pipeline $pipeline, \Closure $accumulator, mixed $initial = null): mixed
{
    $result = $initial;
    foreach ($pipeline as $value) {
        $result = $accumulator($result, $value);
    }
    return $result;
}

/**
 * Discards all remaining items and returns the number of discarded items.
 *
 * @template TValue
 *
 * @param Pipeline<TValue> $pipeline
 *
 * @return int
 */
function discard(Pipeline $pipeline): int
{
    $count = 0;

    while (null !== $pipeline->continue()) {
        $count++;
    }

    return $count;
}

/**
 * Collects all items from a pipeline into an array.
 *
 * @template TValue
 *
 * @param Pipeline<TValue> $pipeline
 *
 * @return array<int, TValue>
 */
function toArray(Pipeline $pipeline): array
{
    return \iterator_to_array($pipeline);
}
