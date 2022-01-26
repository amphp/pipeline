<?php

namespace Amp\Pipeline;

use Amp\Cancellation;
use Amp\Pipeline\Internal\ConcurrentQueueIterator;
use Amp\Pipeline\Internal\QueueState;
use Amp\Pipeline\Internal\Sequence;
use function Amp\async;

/**
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentIterableIterator implements ConcurrentIterator
{
    private Sequence $sequence;

    private int $consumePosition = 0;

    /** @var ConcurrentIterator<T> */
    private ConcurrentIterator $iterator;

    /**
     * @param iterable<array-key, T> $iterable
     */
    public function __construct(iterable $iterable)
    {
        $queue = new QueueState();
        $this->iterator = new ConcurrentQueueIterator($queue);
        $this->sequence = new Sequence;

        async(function () use ($queue, $iterable) {
            try {
                $this->sequence->await($producePosition = 1);

                foreach ($iterable as $value) {
                    $queue->push($value);
                    $this->sequence->await(++$producePosition);
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
        $this->sequence->resume($this->consumePosition++);

        return $this->iterator->continue($cancellation);
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
        $this->sequence->resume(\PHP_INT_MAX - 1);

        $this->iterator->dispose();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
