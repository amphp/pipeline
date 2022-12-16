<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\Cancellation;
use Revolt\EventLoop\FiberLocal;
use function Amp\delay;

/**
 * Same as {@see ConcurrentArrayIterator}, but with a configurable delay to allow testing slow known-size iterators.
 */
final class ConcurrentDelayedArrayIterator implements ConcurrentIterator
{
    private int $position = 0;
    private int $size;

    private array $values;

    private FiberLocal $currentPosition;

    private ?DisposedException $disposed = null;

    private float $delay;

    public function __construct(float $delay, array $values)
    {
        $this->delay = $delay;
        $this->values = \PHP_VERSION_ID >= 80100 && \array_is_list($values) ? $values : \array_values($values);
        $this->size = \count($values);
        $this->currentPosition = new FiberLocal(fn () => throw new \Error('Call continue() before calling get()'));
    }

    public function continue(?Cancellation $cancellation = null): bool
    {
        $position = $this->position++;
        if ($position < $this->size) {
            delay($this->delay);

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

    public function isComplete(): bool
    {
        return $this->position >= $this->size;
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
