<?php

namespace Amp\Pipeline\Internal\Operator;

use Amp\Future;
use Amp\Pipeline\Emitter;
use Amp\Pipeline\PipelineOperator;
use Amp\Pipeline\Pipeline;
use Amp\Sync\Lock;
use Amp\Sync\Semaphore;
use Revolt\EventLoop;

/**
 * @template TValue
 * @template-implements PipelineOperator<TValue, TValue>
 *
 * @internal
 */
final class ConcurrentOperator implements PipelineOperator
{
    /**
     * @param Semaphore $semaphore Concurrency limited to number of locks provided by the semaphore.
     * @param PipelineOperator[] $operators Set of operators to apply to each concurrent pipeline.
     * @param bool $ordered True to maintain order of emissions on output pipeline.
     */
    public function __construct(
        private Semaphore $semaphore,
        private array $operators,
        private bool $ordered,
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        $destination = new Emitter();

        EventLoop::queue(function () use ($pipeline, $destination): void {
            $queue = new \SplQueue();
            $emitters = new \ArrayObject();

            // Add initial source which will dispose of destination if no values are emitted.
            $queue->push($this->createEmitter($destination, $queue, $emitters));

            $previous = Future::complete();

            try {
                foreach ($pipeline as $value) {
                    $lock = $this->semaphore->acquire();

                    if ($destination->isComplete() || $destination->isDisposed()) {
                        return;
                    }

                    if ($queue->isEmpty()) {
                        $emitter = $this->createEmitter($destination, $queue, $emitters);
                    } else {
                        $emitter = $queue->shift();
                    }

                    $previous = $emitter->emit([$value, $lock, $previous]);
                }

                $previous->await();
            } catch (\Throwable $exception) {
                try {
                    $previous->await();
                } catch (\Throwable) {
                    // Exception ignored in case destination is disposed while waiting.
                }

                if (!$destination->isComplete()) {
                    $destination->error($exception);
                }
            } finally {
                foreach ($emitters as $emitter) {
                    $emitter->complete();
                }
            }
        });

        return $destination->pipe();
    }

    private function createEmitter(Emitter $destination, \SplQueue $queue, \ArrayObject $emitters): Emitter
    {
        $emitter = new Emitter();
        $emitters->append($emitter);

        EventLoop::queue(function () use ($emitters, $emitter, $destination, $queue): void {
            $operatorEmitter = new Emitter();
            $operatorPipeline = $operatorEmitter->pipe();
            foreach ($this->operators as $operator) {
                $operatorPipeline = $operator->pipe($operatorPipeline);
            }

            try {
                /**
                 * @var TValue $value
                 * @var Lock $lock
                 * @var Future $previous
                 */
                foreach ($emitter->pipe() as [$value, $lock, $previous]) {
                    $operatorEmitter->emit($value)->ignore();
                    $previous->ignore();

                    try {
                        if (null === $value = $operatorPipeline->continue()) {
                            break;
                        }
                    } finally {
                        $queue->push($emitter);
                        $lock->release();
                    }

                    if ($this->ordered) {
                        $previous->await();
                    }

                    if ($destination->isComplete()) {
                        break;
                    }

                    $destination->yield($value);
                }

                $operatorEmitter->complete();

                // Only complete the destination once all outstanding pipelines have completed.
                if ($queue->count() === $emitters->count() && !$destination->isComplete()) {
                    $destination->complete();
                }
            } catch (\Throwable $exception) {
                $operatorEmitter->error($exception);
                if (!$destination->isComplete()) {
                    $destination->error($exception);
                }
            }
        });

        return $emitter;
    }
}
