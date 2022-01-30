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
    private ?Limit $limit;

    /** @var ConcurrentIterator<T> */
    private ConcurrentIterator $iterator;

    /**
     * @template R
     *
     * @param ConcurrentIterator<T> $iterator
     * @param int $concurrency
     * @param bool $ordered
     * @param bool $lazy
     * @param \Closure(T, int):iterable<R> $flatMap
     */
    public function __construct(ConcurrentIterator $iterator, int $concurrency, bool $ordered, bool $lazy, \Closure $flatMap)
    {
        $queue = new QueueState;
        $this->iterator = new ConcurrentQueueIterator($queue);
        $this->limit = $lazy ? new Limit : null;
        $order = $ordered ? new Sequence : null;

        $stop = FlatMapOperation::getStopMarker();

        $futures = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $futures[] = async(function () use ($queue, $iterator, $flatMap, $order, $stop) {
                $this->limit?->await();

                foreach ($iterator as $position => $value) {
                    // The operation runs concurrently, but the emits are at the correct position
                    $iterable = $flatMap($value, $position);

                    $order?->await($position);

                    foreach ($iterable as $item) {
                        if ($item === $stop) {
                            $queue->complete();
                            break 2;
                        }

                        $queue->push($item);
                        $this->limit?->provide(-1); // don't await, because it might lead to deadlocks with order?->await
                    }

                    $order?->resume($position);
                }

                $this->limit?->ignore();
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
        $this->limit?->provide(1);

        try {
            return $this->iterator->continue($cancellation);
        } catch (CancelledException $cancelledException) {
            $this->limit?->provide(-1);

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
        $this->iterator->dispose();
        $this->limit?->ignore();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
