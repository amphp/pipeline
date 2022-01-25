<?php

namespace Amp\Pipeline;

use Amp\Future;
use Revolt\EventLoop;
use function Amp\async;

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
    $iterators = [];

    foreach ($pipelines as $key => $pipeline) {
        if (!$pipeline instanceof Pipeline) {
            throw new \TypeError(\sprintf(
                'Argument #1 ($pipelines) must be of type array<%s>, %s given at key %s',
                Pipeline::class,
                \get_debug_type($pipeline),
                $key
            ));
        }

        $iterators[$key] = $pipeline->getIterator();
    }

    return Pipeline::fromClosure(static function () use ($iterators): \Generator {
        while (true) {
            $next = Future\await(\array_map(
                static fn (ConcurrentIterator $iterator) => async(static function () use ($iterator) {
                    if ($iterator->continue()) {
                        return [$iterator->getValue()];
                    }

                    return null;
                }),
                $iterators
            ));

            // Reconstruct emit array to ensure keys are in same iteration order as pipelines.
            $emit = [];
            foreach ($iterators as $key => $pipeline) {
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
