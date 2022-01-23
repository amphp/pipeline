<?php

namespace Amp\Pipeline\Internal;

use Revolt\EventLoop;

/** @internal */
final class Sequence
{
    private int $position = 0;
    private array $suspensions = [];

    public function await(int $position): void
    {
        \assert($position >= $this->position);

        if ($position === $this->position) {
            return;
        }

        \assert(!isset($this->suspensions[$position]));

        $suspension = EventLoop::getSuspension();
        $this->suspensions[$position] = $suspension;
        $suspension->suspend();
    }

    public function arrive(int $position): void
    {
        \assert($position === $this->position);

        $this->position++;

        if (isset($this->suspensions[$this->position])) {
            $this->suspensions[$this->position]->resume();
            unset($this->suspensions[$this->position]);
        }
    }
}
