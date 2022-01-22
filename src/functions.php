<?php

namespace Amp\Pipeline;

use Amp\Future;
use Amp\Pipeline\Internal\ConcurrentSourceIterator;
use Amp\Pipeline\Internal\Source;
use Revolt\EventLoop;
use function Amp\async;

/**
 * Creates a pipeline from the given iterable, emitting each value.
 *
 * @template T
 *
 * @param iterable<array-key, T>|\Closure():iterable<array-key, T> $iterable Elements to emit.
 *
 * @return Pipeline<T>
 */
function fromIterable(\Closure|iterable $iterable): Pipeline
{
    if ($iterable instanceof \Closure) {
        $iterable = $iterable();

        if (!\is_iterable($iterable)) {
            throw new \TypeError('Return value of argument #1 ($iterable) must be of type iterable, ' . \get_debug_type($iterable) . ' returned');
        }
    }

    if ($iterable instanceof Pipeline) {
        return $iterable;
    }

    /** @psalm-suppress RedundantConditionGivenDocblockType */
    if (!$iterable instanceof \Generator) {
        $iterable = (static fn () => yield from $iterable)();
    }

    if (\is_array($iterable)) {
        return new Pipeline(new ConcurrentArrayIterator($iterable));
    }

    $source = new Source();

    EventLoop::queue(static function () use ($iterable, $source): void {
        try {
            foreach ($iterable as $value) {
                $source->yield($value);
            }

            $source->complete();
        } catch (\Throwable $exception) {
            $source->error($exception);
        } finally {
            $source->dispose();
        }
    });

    return new Pipeline(new ConcurrentSourceIterator($source));
}

/**
 * Creates a pipeline that emits values emitted from any pipeline in the array of pipelines.
 *
 * @template T
 *
 * @param Pipeline<T>[] $pipelines
 *
 * @return Pipeline<T>
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
            Future\await($futures);

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
 * @template T
 *
 * @param Pipeline<T>[] $pipelines
 *
 * @return Pipeline<T>
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

    return fromIterable(static function () use ($pipelines): \Generator {
        foreach ($pipelines as $pipeline) {
            foreach ($pipeline as $value) {
                yield $value;
            }
        }
    });
}

/**
 * Combines all given pipelines into one pipeline, emitting an array of values only after each pipeline has emitted a
 * value. The returned pipeline completes when any pipeline completes or errors when any pipeline errors.
 *
 * @template Tk as array-key
 * @template Tv
 *
 * @param array<Tk, Pipeline<Tv>> $pipelines
 *
 * @return Pipeline<array<Tk, Tv>>
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

        $pipelines[$key] = $pipeline->getIterator();
    }

    return fromIterable(static function () use ($pipelines): \Generator {
        while (true) {
            $next = Future\await(\array_map(
                static fn (ConcurrentIterator $iterator) => async(static function () use ($iterator) {
                    if ($iterator->continue()) {
                        return [$iterator->get()];
                    }

                    return null;
                }),
                $pipelines
            ));

            // Reconstruct emit array to ensure keys are in same iteration order as pipelines.
            $emit = [];
            foreach ($pipelines as $key => $pipeline) {
                $value = $next[$key];
                if ($value === null) {
                    return;
                }

                $emit[$key] = $value[0];
            }

            yield $emit;
        }
    });
}
