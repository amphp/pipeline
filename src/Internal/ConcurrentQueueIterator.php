<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;

/** @internal */
final class ConcurrentQueueIterator implements ConcurrentIterator
{
    private QueueState $state;

    public function __construct(QueueState $state)
    {
        $this->state = $state;
    }

    public function __destruct()
    {
        $this->state->dispose();
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        return $this->state->continue($cancellation);
    }

    public function getValue(): mixed
    {
        return $this->state->getValue();
    }

    public function getPosition(): int
    {
        return $this->state->getPosition();
    }

    public function dispose(): void
    {
        $this->state->dispose();
    }

    public function getIterator(): \Traversable
    {
        while ($this->state->continue()) {
            yield $this->state->getPosition() => $this->state->getValue();
        }
    }
}
