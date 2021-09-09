<?php


namespace Amp\Pipeline;

use Amp\Future;
use function Amp\Future\all;
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
 * Creates a pipeline from the given iterable, emitting each value. The iterable may contain promises. If any
 * promise fails, the returned pipeline will fail with the same reason.
 *
 * @template TValue
 *
 * @param iterable $iterable Elements to emit.
 *
 * @psalm-param iterable<array-key, TValue> $iterable
 *
 * @return Pipeline<TValue>
 *
 * @throws \TypeError If the argument is not an array or instance of \Traversable.
 */
function fromIterable(iterable $iterable): Pipeline
{
    return new AsyncGenerator(static function () use ($iterable): \Generator {
        foreach ($iterable as $value) {
            if ($value instanceof Future) {
                $value = $value->join();
            }

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

        $futures[] = spawn(static function () use (&$subject, $pipeline): void {
            while ((null !== $value = $pipeline->continue()) && $subject !== null) {
                $subject->yield($value);
            }
        });
    }

    defer(static function () use (&$subject, $futures, $pipelines): void {
        try {
            all($futures);
            $subject->complete();
        } catch (\Throwable $exception) {
            $subject->error($exception);
        } finally {
            $subject = null;
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
        foreach ($pipelines as $pipeline) {
            while (null !== $value = $pipeline->continue()) {
                yield $value;
            }
        }
    });
}

/**
 * @template TValue
 * @template TReturn
 *
 * @param callable(TValue):TReturn $onEmit
 *
 * @return Operator<TValue, TReturn>
 */
function map(callable $onEmit): Operator
{
    return new Operator\MapOperator($onEmit);
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
