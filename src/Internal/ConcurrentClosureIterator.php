<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Revolt\EventLoop\FiberLocal;

/**
 * @internal
 *
 * @template T
 * @template-implements ConcurrentIterator<T>
 */
final class ConcurrentClosureIterator implements ConcurrentIterator
{
    private \Closure $supplier;

    /** @var FiberLocal<T|null> */
    private FiberLocal $currentValue;

    /** @var FiberLocal<int|null> */
    private FiberLocal $currentPosition;

    private int $position = 0;

    private ?\Throwable $exception = null;

    public function __construct(\Closure $supplier)
    {
        $this->supplier = $supplier;
        $this->currentValue = new FiberLocal(fn () => throw new \Error('Call continue() before calling get()'));
        $this->currentPosition = new FiberLocal(fn () => throw new \Error('Call continue() before calling get()'));
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        if ($this->exception) {
            throw $this->exception;
        }

        try {
            $value = ($this->supplier)($cancellation);
            $this->currentValue->set($value);
            $position = $this->position++;
            $this->currentPosition->set($position);
        } catch (CancelledException $cancelledException) {
            throw $cancelledException;
        } catch (\Throwable $exception) {
            $this->currentValue->set(null);
            $this->currentPosition->set(null);
            $this->exception = $exception;

            throw $exception;
        }

        return true;
    }

    public function getValue(): mixed
    {
        $position = $this->currentPosition->get();
        if ($position === null) {
            throw new \Error('continue() returned false, no value available afterwards');
        }

        return $this->currentValue->get();
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
        $this->exception ??= new DisposedException;
    }

    public function getIterator(): \Traversable
    {
        while ($this->continue()) {
            yield $this->getPosition() => $this->getValue();
        }
    }
}
