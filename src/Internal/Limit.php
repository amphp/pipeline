<?php

namespace Amp\Pipeline\Internal;

use Revolt\EventLoop;

/** @internal */
final class Limit
{
    private int $available = 0;

    /** @var \SplQueue<EventLoop\Suspension> */
    private \SplQueue $suspensions;

    public function __construct()
    {
        $this->suspensions = new \SplQueue;
    }

    public function await(): void
    {
        while ($this->available === 0) {
            $suspension = EventLoop::getSuspension();
            $this->suspensions->push($suspension);
            $suspension->suspend();
        }

        $this->available--;
    }

    public function ignore(): void
    {
        $this->available = \PHP_INT_MAX;

        while (!$this->suspensions->isEmpty()) {
            $suspension = $this->suspensions->pop();
            $suspension->resume();
        }
    }

    public function provide(int $count): void
    {
        if ($this->available + $count < \PHP_INT_MAX) {
            $this->available += $count;
        }

        for ($i = 0; $i < $count && !$this->suspensions->isEmpty(); $i++) {
            $suspension = $this->suspensions->pop();
            $suspension->resume();
        }
    }
}
