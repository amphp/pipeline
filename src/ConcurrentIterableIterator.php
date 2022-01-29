<?php

namespace Amp\Pipeline;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\Internal\ConcurrentQueueIterator;
use Amp\Pipeline\Internal\Limit;
use Amp\Pipeline\Internal\QueueState;
use function Amp\async;

/**
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentIterableIterator implements ConcurrentIterator
{
    private Limit $limit;

    /** @var ConcurrentIterator<T> */
    private ConcurrentIterator $iterator;

    /**
     * @param iterable<array-key, T> $iterable
     */
    public function __construct(iterable $iterable)
    {
        $queue = new QueueState();
        $this->iterator = new ConcurrentQueueIterator($queue);
        $this->limit = new Limit;

        async(function () use ($queue, $iterable) {
            try {
                $this->limit->await();

                foreach ($iterable as $value) {
                    $queue->push($value);
                    $this->limit->await();
                }

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
        $this->limit->provide(1);

        try {
            return $this->iterator->continue($cancellation);
        } catch (CancelledException $cancelledException) {
            $this->limit->provide(-1);

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
        $this->limit->ignore();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
