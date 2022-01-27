<?php

namespace Amp\Pipeline\Internal;

use Revolt\EventLoop;

/** @internal */
final class Sequence
{
    private int $position = 0;
    private array $suspensions = [];

    private int $errorPosition = \PHP_INT_MAX;
    private ?\Throwable $error = null;

    public function waiting(): int
    {
        return \count($this->suspensions);
    }

    public function await(int $position): void
    {
        if ($position >= $this->errorPosition) {
            \assert($this->error !== null);

            throw $this->error;
        }

        if ($position <= $this->position) {
            return;
        }

        \assert(!isset($this->suspensions[$position]));

        $suspension = EventLoop::getSuspension();
        $this->suspensions[$position] = $suspension;
        $suspension->suspend();
    }

    public function error(int $errorPosition, \Throwable $exception): void
    {
        $this->errorPosition = $errorPosition;
        $this->error = $exception;

        if ($this->suspensions) {
            $max = \max(\array_keys($this->suspensions));

            for ($i = $errorPosition; $i <= $max; $i++) {
                if (isset($this->suspensions[$i])) {
                    $this->suspensions[$i]->throw($exception);
                    unset($this->suspensions[$i]);
                }
            }
        }
    }

    public function resume(int $position): void
    {
        if ($position < $this->position) {
            return;
        }

        $newPosition = \max($position, $this->position) + 1;

        if ($newPosition === \PHP_INT_MAX) {
            foreach ($this->suspensions as $suspension) {
                $suspension->resume();
            }

            $this->suspensions = [];
        } else {
            for ($i = $this->position + 1; $i <= $newPosition; $i++) {
                if (isset($this->suspensions[$i])) {
                    if ($this->error && $i >= $this->errorPosition) {
                        $this->suspensions[$i]->throw($this->error);
                    } else {
                        $this->suspensions[$i]->resume();
                    }

                    unset($this->suspensions[$i]);
                }
            }
        }

        $this->position = $newPosition;
    }
}
