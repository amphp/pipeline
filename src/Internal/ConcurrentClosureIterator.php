<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Pipeline\ConcurrentIterator;
use Revolt\EventLoop;

/**
 * @internal
 *
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentClosureIterator implements ConcurrentIterator
{
    private \Closure $supplier;

    private \SplQueue $sources;

    private QueueState $queue;

    private Sequence $sequence;

    private DeferredCancellation $deferredCancellation;

    private int $cancellations = 0;

    private int $position = 0;

    public function __construct(\Closure $supplier)
    {
        $this->supplier = $supplier;
        $this->sequence = new Sequence();
        $this->queue = new QueueState();
        $this->sources = new \SplQueue();
        $this->deferredCancellation = new DeferredCancellation();
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        if ($this->queue->isComplete()) {
            return $this->queue->continue($cancellation);
        }

        if ($this->cancellations) {
            --$this->cancellations;
            return $this->queue->continue($cancellation);
        }

        if ($this->sources->isEmpty()) {
            $queue = $this->queue;
            $sources = $this->sources;
            $sequence = $this->sequence;
            $supplier = $this->supplier;
            $deferredCancellation = $this->deferredCancellation;
            EventLoop::queue(static function (int $position) use (
                $queue,
                $sources,
                $sequence,
                $supplier,
                $deferredCancellation
            ): void {
                $suspension = EventLoop::getSuspension();

                do {
                    try {
                        $value = $supplier($deferredCancellation->getCancellation());
                    } catch (\Throwable $exception) {
                        $sequence->await($position);
                        if (!$queue->isComplete()) {
                            $queue->error($exception);
                            $deferredCancellation->cancel($exception);
                        }
                        $sequence->resume($position);
                    } finally {
                        $sources->enqueue($suspension);
                    }

                    $sequence->await($position);
                    if (!$queue->isComplete()) {
                        $queue->push($value);
                    }
                    $sequence->resume($position);
                } while ($position = $suspension->suspend());
            }, $this->position++);
        } else {
            $suspension = $this->sources->dequeue();
            $suspension->resume($this->position++);
        }

        if ($cancellation) {
            $cancellations = &$this->cancellations;
            $id = $cancellation->subscribe(static function () use (&$cancellations): void {
                ++$cancellations;
            });
        }

        try {
            return $this->queue->continue($cancellation);
        } finally {
            $cancellation?->unsubscribe($id);
        }
    }

    public function getValue(): mixed
    {
        return $this->queue->getValue();
    }

    public function getPosition(): int
    {
        return $this->queue->getPosition();
    }

    public function dispose(): void
    {
        while (!$this->sources->isEmpty()) {
            $this->sources->dequeue();
        }

        $this->queue->dispose();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
