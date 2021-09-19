<?php

namespace Amp\Pipeline\Operator;

use Amp\Future;
use Amp\Pipeline\Operator;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Subject;
use Amp\Sync\Lock;
use Amp\Sync\Semaphore;
use function Revolt\EventLoop\defer;

/**
 * @template TValue
 *
 * @template-implements Operator<TValue, TValue>
 */
final class ConcurrentOperator implements Operator
{
    /**
     * @param Semaphore $semaphore
     * @param Operator[] $operators
     */
    public function __construct(
        private Semaphore $semaphore,
        private array $operators,
        private bool $ordered,
    ) {
    }

    public function pipe(Pipeline $pipeline): Pipeline
    {
        $destination = new Subject();

        defer(function () use ($pipeline, $destination): void {
            $queue = new \SplQueue();
            $subjects = new \ArrayObject();

            // Add initial source which will dispose of destination if no values are emitted.
            $queue->push($this->createSubject($destination, $queue, $subjects));

            $previous = Future::complete(null);

            try {
                foreach ($pipeline as $value) {
                    $lock = $this->semaphore->acquire();

                    if ($destination->isComplete() || $destination->isDisposed()) {
                        return;
                    }

                    if ($queue->isEmpty()) {
                        $subject = $this->createSubject($destination, $queue, $subjects);
                    } else {
                        $subject = $queue->shift();
                    }

                    $previous = $subject->emit([$value, $lock, $previous]);
                }

                $previous->await();
            } catch (\Throwable $exception) {
                try {
                    $previous->await();
                } catch (\Throwable $ignored) {
                    // Exception ignored in case destination is disposed while waiting.
                }

                if (!$destination->isComplete()) {
                    $destination->error($exception);
                }
            } finally {
                foreach ($subjects as $subject) {
                    $subject->complete();
                }
            }
        });

        return $destination->asPipeline();
    }

    private function createSubject(Subject $destination, \SplQueue $queue, \ArrayObject $subjects): Subject {
        $subject = new Subject();
        $subjects->append($subject);

        defer(function () use ($subjects, $subject, $destination, $queue): void {
            $operatorSubject = new Subject();
            $operatorPipeline = $operatorSubject->asPipeline();
            foreach ($this->operators as $operator) {
                $operatorPipeline = $operator->pipe($operatorPipeline);
            }

            try {
                /**
                 * @var  $value TValue
                 * @var  $lock Lock
                 * @var  $previous Future
                 */
                foreach ($subject->asPipeline() as [$value, $lock, $previous]) {
                    $operatorSubject->emit($value);

                    try {
                        if (null === $value = $operatorPipeline->continue()) {
                            break;
                        }
                    } finally {
                        $queue->push($subject);
                        $lock->release();
                    }

                    if ($this->ordered) {
                        $previous->await();
                    }

                    $destination->yield($value);
                }

                $operatorSubject->complete();

                // Only complete the destination once all outstanding pipelines have completed.
                if ($queue->count() === $subjects->count() && !$destination->isComplete()) {
                    $destination->complete();
                }
            } catch (\Throwable $exception) {
                $operatorSubject->error($exception);
                if (!$destination->isComplete()) {
                    $destination->error($exception);
                }
            }
        });

        return $subject;
    }
}
