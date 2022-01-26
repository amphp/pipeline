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
        if ($position <= $this->position) {
            return;
        }

        \assert(!isset($this->suspensions[$position]));

        $suspension = EventLoop::getSuspension();
        $this->suspensions[$position] = $suspension;
        $suspension->suspend();
    }

    public function resume(int $position): void
    {
        $newPosition = \max($position, $this->position) + 1;

        for ($i = $this->position + 1; $i <= $newPosition; $i++) {
            if (isset($this->suspensions[$i])) {
                $this->suspensions[$i]->resume();
                unset($this->suspensions[$i]);
            }
        }

        $this->position = $newPosition;
    }
}
