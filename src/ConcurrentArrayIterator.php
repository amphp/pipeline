<?php

namespace Amp\Pipeline;

use Amp\Cancellation;
use Revolt\EventLoop\FiberLocal;

final class ConcurrentArrayIterator implements ConcurrentIterator
{
    private int $position = 0;
    private readonly int $size;

    private readonly array $values;

    private readonly FiberLocal $currentPosition;

    private ?DisposedException $disposed = null;

    public function __construct(array $values)
    {
        $this->values = \array_is_list($values) ? $values : \array_values($values);
        $this->size = \count($values);
        $this->currentPosition = new FiberLocal(fn () => throw new \Error('Call continue() before calling get()'));
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        if ($this->disposed) {
            throw $this->disposed;
        }

        $position = $this->position++;
        if ($position < $this->size) {
            $this->currentPosition->set($position);
            return true;
        }

        $this->currentPosition->set(null);
        return false;
    }

    public function getValue(): mixed
    {
        $position = $this->currentPosition->get();
        if ($position === null) {
            throw new \Error('continue() returned false, no value available afterwards');
        }

        return $this->values[$position];
    }

    public function getPosition(): int
    {
        $position = $this->currentPosition->get();
        if ($position === null) {
            throw new \Error('continue() returned false, no position available afterwards');
        }

        return $position;
    }

    public function dispose(): void
    {
        $this->disposed ??= new DisposedException;
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
