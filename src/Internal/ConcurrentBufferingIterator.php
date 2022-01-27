<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use function Amp\async;
use function Amp\Future\await;

/**
 * @internal
 *
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentBufferingIterator implements ConcurrentIterator
{
    /** @var ConcurrentIterator<T> */
    private ConcurrentIterator $iterator;

    /**
     * @template R
     *
     * @param ConcurrentIterator<T> $iterator
     * @param int $concurrency
     * @param bool $ordered
     */
    public function __construct(ConcurrentIterator $iterator, int $concurrency, bool $ordered)
    {
        $queue = new QueueState();
        $this->iterator = new ConcurrentQueueIterator($queue);
        $order = $ordered ? new Sequence : null;

        $futures = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $futures[] = async(function () use ($queue, $iterator, $order) {
                foreach ($iterator as $position => $value) {
                    $order?->await($position);
                    $queue->push($value);
                    $order?->resume($position);
                }
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
