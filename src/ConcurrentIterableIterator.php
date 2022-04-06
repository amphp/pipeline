<?php

namespace Amp\Pipeline;

use Amp\Cancellation;
use Amp\Pipeline\Internal\ConcurrentQueueIterator;
use Amp\Pipeline\Internal\QueueState;
use function Amp\async;

/**
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentIterableIterator implements ConcurrentIterator
{
    /** @var ConcurrentIterator<T> */
    private readonly ConcurrentIterator $iterator;

    /**
     * @param iterable<array-key, T> $iterable
     */
    public function __construct(iterable $iterable)
    {
        $queue = new QueueState();
        $this->iterator = new ConcurrentQueueIterator($queue);

        async(static function () use ($queue, $iterable): void {
            try {
                foreach ($iterable as $value) {
                    $queue->push($value);

                    if ($queue->isDisposed()) {
                        return;
                    }
                }

                $queue->complete();
            } catch (\Throwable $e) {
                $queue->error($e);
            }
        });
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
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
        $this->iterator->dispose();
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
