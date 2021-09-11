<?php


namespace Amp\Pipeline;

use Amp\Future;
use function Amp\Future\spawn;
use function Revolt\EventLoop\defer;

/**
 * Creates a source that can create any number of pipelines by calling {@see Source::asPipeline()}. The new pipelines
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
 * @param iterable $iterable Elements to emit.
 *
 * @psalm-param iterable<array-key, TValue> $iterable
 *
 * @return Pipeline<TValue>
 */
function fromIterable(iterable $iterable): Pipeline
{
    return new AsyncGenerator(static function () use ($iterable): \Generator {
        foreach ($iterable as $value) {
            yield $value;
        }
    });
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
    $subject = new Subject;

    $futures = [];
    foreach ($pipelines as $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf('Must provide only instances of %s to %s', Pipeline::class, __FUNCTION__));
        }

        $futures[] = spawn(static function () use ($subject, $pipeline): void {
            foreach ($pipeline as $value) {
                if ($subject->isComplete()) {
                    return;
                }
                $subject->yield($value);
            }
        });
    }

    defer(static function () use ($subject, $futures, $pipelines): void {
        try {
            Future\all($futures);
            $subject->complete();
        } catch (\Throwable $exception) {
            $subject->error($exception);
        } finally {
            foreach ($pipelines as $pipeline) {
                $pipeline->dispose();
            }
        }
    });

    return $subject->asPipeline();
}

/**
 * Concatenates the given pipelines into a single pipeline, emitting from a single pipeline at a time. The
 * prior pipeline must complete before values are emitted from any subsequent pipelines. Streams are concatenated
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
    foreach ($pipelines as $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf('Must provide only instances of %s to %s', Pipeline::class, __FUNCTION__));
        }
    }

    return new AsyncGenerator(function () use ($pipelines): \Generator {
        try {
            foreach ($pipelines as $pipeline) {
                foreach ($pipeline as $value) {
                    yield $value;
                }
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
 * @return Pipeline<array<TKey, TValue>>
 */
function zip(array $pipelines): Pipeline
{
    foreach ($pipelines as $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf('Must provide only instances of %s to %s', Pipeline::class, __FUNCTION__));
        }
    }

    return new AsyncGenerator(static function () use ($pipelines): \Generator {
        try {
            while (true) {
                $next = Future\all(\array_map(
                    static fn (Pipeline $pipeline) => Future\spawn(static fn () => $pipeline->continue()),
                    $pipelines
                ));

                if (\in_array(needle: null, haystack: $next, strict: true)) {
                    return;
                }

                yield $next;
            }
        } finally {
            foreach ($pipelines as $pipeline) {
                $pipeline->dispose();
            }
        }
    });
}

/**
 * @template TValue
 * @template TReturn
 *
 * @param callable(TValue):TReturn $map
 *
 * @return Operator<TValue, TReturn>
 */
function map(callable $map): Operator
{
    return new Operator\MapOperator($map);
}

/**
 * @template TValue
 *
 * @param callable(TValue):bool $filter
 *
 * @return Operator<TValue, TValue>
 */
function filter(callable $filter): Operator
{
    return new Operator\FilterOperator($filter);
}

/**
 * Delay the emission of each value for the given amount of time.
 *
 * @template TValue
 *
 * @param float $timeout
 * @return Operator<TValue, TValue>
 */
function delay(float $timeout): Operator
{
    return new Operator\DelayOperator($timeout);
}

/**
 * Values emitted from the source pipeline are not emitted on the returned pipline until the $delayWhen pipeline emits.
 * The returned pipeline completes or errors when either the source or $delayWhen completes or errors.
 *
 * @template TValue
 *
 * @param Pipeline<mixed> $delayWhen
 * @return Operator<TValue, TValue>
 */
function delayWhen(Pipeline $delayWhen): Operator
{
    return new Operator\DelayWhenOperator($delayWhen);
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
 * @param callable(TValue):bool $predicate
 * @return Operator<TValue, TValue>
 */
function skipWhile(callable $predicate): Operator
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
 * @param callable(TValue):bool $predicate
 * @return Operator<TValue, TValue>
 */
function takeWhile(callable $predicate): Operator
{
    return new Operator\TakeWhileOperator($predicate);
}

/**
 * Invokes the given function each time a value is emitted to perform side effects with the value.
 * While this could be accomplished with map, the intention of this operator is to keep those functions pure.
 *
 * @template TValue
 *
 * @param callable(TValue):void $tap
 * @return Operator<TValue, TValue>
 */
function tap(callable $tap): Operator
{
    return new Operator\TapOperator($tap);
}

/**
 * Invokes the given function when the pipeline completes, either successfully or with an error.
 *
 * @template TValue
 *
 * @param callable():void $finally
 * @return Operator<TValue, TValue>
 */
function finalize(callable $finally): Operator
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
function sampleTime(float $period): Operator
{
    return new Operator\SampleWhenOperator(
        (new AsyncGenerator(static function (): \Generator {
            while (true) {
                yield 0;
            }
        }))->pipe(delay($period))
    );
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
