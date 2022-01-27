<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\ConcurrentIterator;
use function Amp\async;
use function Amp\Future\await;

/**
 * @internal
 *
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentFlatMapIterator implements ConcurrentIterator
{
    private Sequence $consumer;

    private int $consumePosition = 0;

    /** @var ConcurrentIterator<T> */
    private ConcurrentIterator $iterator;

    /**
     * @template R
     *
     * @param ConcurrentIterator<T> $iterator
     * @param int $concurrency
     * @param bool $ordered
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(ConcurrentIterator $iterator, int $concurrency, bool $ordered, \Closure $flatMap)
    {
        $queue = new QueueState();
        $this->iterator = new ConcurrentQueueIterator($queue);
        $this->consumer = new Sequence;
        $order = $ordered ? new Sequence : null;

        $producePosition = 0;
        $futures = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $futures[] = async(function () use ($queue, $iterator, $flatMap, $order, &$producePosition) {
                $this->consumer->await(++$producePosition);

                foreach ($iterator as $position => $value) {
                    // The operation runs concurrently, but the emits are at the correct position
                    $iterable = $flatMap($value, $position);

                    $order?->await($position);

                    foreach ($iterable as $item) {
                        $queue->push($item);
                    }

                    $order?->resume($position);
                    $this->consumer->await(++$producePosition);
                }

                $this->consumer->resume(\PHP_INT_MAX - 1);
            });
        }

        async(function () use ($futures, $queue) {
            try {
                await($futures);
                $queue->complete();
            } catch (\Throwable $e) {
                $queue->error($e);
            } finally {
                $queue->dispose();
            }
        });
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        $this->consumer->resume($this->consumePosition++);

        try {
            return $this->iterator->continue($cancellation);
        } catch (CancelledException $cancelledException) {
            $this->consumePosition--;

            throw $cancelledException;
        }
    }

    public function getValue(): mixed
    {
        return $this->iterator->getValue();
    }

    public function getPosition(): int
    {
        return $this->iterator->getPosition();
    }

    public function dispose(): void
    {
        $this->consumer->resume(\PHP_INT_MAX - 1);

        $this->iterator->dispose();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
