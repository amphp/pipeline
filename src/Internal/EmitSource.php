<?php

namespace Amp\Pipeline\Internal;

use Amp\Deferred;
use Amp\Future;
use Amp\Internal;
use Amp\Pipeline\DisposedException;
use Revolt\EventLoop\Loop;
use Revolt\EventLoop\Suspension;

/**
 * Class used internally by {@see Pipeline} implementations. Do not use this class in your code, instead compose your
 * class from one of the available classes implementing {@see Pipeline}.
 *
 * @internal
 *
 * @template TValue
 * @template TSend
 */
final class EmitSource
{
    private bool $completed = false;

    private ?\Throwable $exception = null;

    /** @var array<int, mixed> */
    private array $emittedValues = [];

    /** @var array<int, array{?\Throwable, mixed}> */
    private array $sendValues = [];

    /** @var array<int, Deferred|Suspension> */
    private array $backPressure = [];

    /** @var Suspension[] */
    private array $waiting = [];

    private int $consumePosition = 0;

    private int $emitPosition = 0;

    private ?array $resolutionTrace = null;

    private bool $disposed = false;

    /** @var callable[]|null */
    private ?array $onDisposal = [];

    /**
     * @return TValue
     */
    public function continue(): mixed
    {
        return $this->next(null, null);
    }

    /**
     * @param TSend $value
     *
     * @return TValue
     */
    public function send(mixed $value): mixed
    {
        if ($this->consumePosition === 0) {
            throw new \Error("Must initialize async generator by calling continue() first");
        }

        return $this->next(null, $value);
    }

    /**
     * @return TValue
     */
    public function throw(\Throwable $exception): mixed
    {
        if ($this->consumePosition === 0) {
            throw new \Error("Must initialize async generator by calling continue() first");
        }

        return $this->next($exception, null);
    }

    /**
     * @param TSend|null $value
     *
     * @return TValue
     */
    private function next(?\Throwable $exception, mixed $value): mixed
    {
        $position = $this->consumePosition++ - 1;

        // Relieve backpressure from prior emit.
        if (isset($this->backPressure[$position])) {
            $placeholder = $this->backPressure[$position];
            unset($this->backPressure[$position]);
            if ($exception) {
                $placeholder instanceof Suspension
                    ? $placeholder->throw($exception)
                    : $placeholder->error($exception);
            } else {
                $placeholder instanceof Suspension
                    ? $placeholder->resume($value)
                    : $placeholder->complete($value);
            }
        } elseif ($position >= 0) {
            // Send-values are indexed as $this->consumePosition - 1.
            $this->sendValues[$position] = [$exception, $value];
        }

        ++$position; // Move forward to next emitted value if available.

        if (isset($this->emittedValues[$position])) {
            $value = $this->emittedValues[$position];
            unset($this->emittedValues[$position]);
            return $value;
        }

        if ($this->completed || $this->disposed) {
            if ($this->exception) {
                throw $this->exception;
            }
            return null;
        }

        // No value has been emitted, suspend fiber to await next value.
        $this->waiting[$position] = $suspension = Loop::createSuspension();
        return $suspension->suspend();
    }

    /**
     * @return void
     *
     * @see Pipeline::dispose()
     */
    public function dispose(): void
    {
        $this->cancel(true);
    }

    public function destroy(): void
    {
        $this->cancel(false);
    }

    private function cancel(bool $cancelPending): void
    {
        try {
            if ($this->completed || $this->disposed) {
                return; // Pipeline already completed or failed.
            }

            $this->finalize(new DisposedException, true);
        } finally {
            if ($this->disposed && $cancelPending) {
                $this->triggerDisposal();
            }
        }
    }

    /**
     * @param callable():void $onDisposal
     *
     * @return void
     *
     * @see Pipeline::onDisposal()
     */
    public function onDisposal(callable $onDisposal): void
    {
        if ($this->disposed) {
            Loop::queue($onDisposal, $this->exception);
            return;
        }

        if ($this->completed) {
            return;
        }

        $this->onDisposal[] = $onDisposal;
    }

    /**
     * @param TValue       $value
     * @param int          $position
     *
     * @return array|null Returns [?\Throwable, mixed] or null if no send value is available.
     *
     * @throws \Error If the pipeline has completed.
     */
    private function push(mixed $value, int $position): ?array
    {
        if ($this->completed) {
            throw new \Error("Pipelines cannot emit values after calling complete");
        }

        if ($value === null) {
            throw new \TypeError("Pipelines cannot emit NULL");
        }

        if ($value instanceof Future) {
            throw new \TypeError("Pipelines cannot emit futures");
        }

        if (isset($this->waiting[$position])) {
            $suspension = $this->waiting[$position];
            unset($this->waiting[$position]);
            $suspension->resume($value);

            if ($this->disposed && empty($this->waiting)) {
                \assert(empty($this->sendValues)); // If $this->waiting is empty, $this->sendValues must be.
                $this->triggerDisposal();
                return [null, null]; // Subsequent push() calls will throw.
            }

            // Send-values are indexed as $this->consumePosition - 1, so use $position for the next value.
            if (isset($this->sendValues[$position])) {
                $pair = $this->sendValues[$position];
                unset($this->sendValues[$position]);
                return $pair;
            }

            return null;
        }

        if ($this->disposed) {
            \assert(isset($this->exception), "Failure exception must be set when disposed");
            // Pipeline has been disposed and no Fibers are still pending.
            return [$this->exception, null];
        }

        $this->emittedValues[$position] = $value;

        return null;
    }

    /**
     * Emits a value from the pipeline. The returned promise is resolved once the emitted value has been consumed or
     * if the pipeline is completed, failed, or disposed.
     *
     * @param TValue $value Value to emit from the pipeline.
     *
     * @return Future<TSend> Resolves with the value sent to the pipeline.
     */
    public function emit(mixed $value): Future
    {
        $position = $this->emitPosition;

        $pair = $this->push($value, $position);

        ++$this->emitPosition;

        if ($pair === null) {
            $this->backPressure[$position] = $deferred = new Deferred;
            return $deferred->getFuture();
        }

        [$exception, $value] = $pair;

        if ($exception) {
            return Future::error($exception);
        }

        return Future::complete($value);
    }

    /**
     * Emits a value from the pipeline, suspending execution until the value is consumed.
     *
     * @param TValue $value Value to emit from the pipeline.
     *
     * @return TSend Returns the value sent to the pipeline.
     */
    public function yield(mixed $value): mixed
    {
        $position = $this->emitPosition;

        $pair = $this->push($value, $position);

        ++$this->emitPosition;

        if ($pair === null) {
            $this->backPressure[$position] = $suspension = Loop::createSuspension();
            return $suspension->suspend();
        }

        [$exception, $value] = $pair;

        if ($exception) {
            throw $exception;
        }

        return $value;
    }

    /**
     * @return bool True if the pipeline has been completed or failed.
     */
    public function isComplete(): bool
    {
        return $this->completed;
    }

    /**
     * @return bool True if the pipeline was disposed.
     */
    public function isDisposed(): bool
    {
        return $this->disposed && empty($this->waiting);
    }

    /**
     * Completes the pipeline.
     *
     * @return void
     *
     * @throws \Error If the iterator has already been completed.
     */
    public function complete(): void
    {
        $this->finalize();
    }

    /**
     * Fails the pipeline.
     *
     * @param \Throwable $exception
     *
     * @return void
     */
    public function error(\Throwable $exception): void
    {
        $this->finalize($exception);
    }

    /**
     * @param \Throwable|null $exception Failure reason or null for success.
     * @param bool            $disposed Flag if the generator was disposed.
     *
     * @return void
     */
    private function finalize(?\Throwable $exception = null, bool $disposed = false): void
    {
        if ($this->completed) {
            $message = "Pipeline has already been completed";

            if (isset($this->resolutionTrace)) {
                $trace = Internal\formatStacktrace($this->resolutionTrace);
                $message .= ". Previous completion trace:\n\n{$trace}\n\n";
            } else {
                // @codeCoverageIgnoreStart
                $message .= ", define environment variable AMP_DEBUG or const AMP_DEBUG = true and enable assertions "
                    . "for a stacktrace of the previous resolution.";
                // @codeCoverageIgnoreEnd
            }

            throw new \Error($message);
        }

        $this->completed = !$disposed; // $disposed is false if complete() or error() invoked
        $this->disposed = $this->disposed ?: $disposed; // Once disposed, do not change flag

        if ($this->completed) { // Record stack trace when calling complete() or error()
            \assert((function () {
                if (Internal\isDebugEnabled()) {
                    $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
                    \array_shift($trace); // remove current closure
                    $this->resolutionTrace = $trace;
                }

                return true;
            })());
        }

        if (isset($this->exception)) {
            return;
        }

        if ($exception !== null) {
            $this->exception = $exception;
        }

        if ($this->disposed) {
            if (empty($this->waiting)) {
                $this->triggerDisposal();
            }
        } else {
            $this->resolvePending();
        }
    }

    private function relieveBackPressure(?\Throwable $exception): void
    {
        $backPressure = $this->backPressure;
        unset($this->backPressure);

        foreach ($backPressure as $placeholder) {
            if ($exception) {
                $placeholder instanceof Suspension
                    ? $placeholder->throw($exception)
                    : $placeholder->error($exception);
            } else {
                $placeholder instanceof Suspension
                    ? $placeholder->resume(null)
                    : $placeholder->complete(null);
            }
        }
    }

    /**
     * Resolves all backpressure and outstanding calls for emitted values.
     */
    private function resolvePending(): void
    {
        $waiting = $this->waiting;
        unset($this->waiting);

        $exception = $this->exception ?? null;

        foreach ($waiting as $suspension) {
            if ($exception) {
                $suspension->throw($exception);
            } else {
                $suspension->resume(null);
            }
        }
    }

    /**
     * Invokes all pending {@see onDisposal()} callbacks and fails pending {@see continue()} promises.
     */
    private function triggerDisposal(): void
    {
        \assert($this->disposed && $this->exception, "Pipeline was not disposed on triggering disposal");

        if ($this->onDisposal === null) {
            return;
        }

        $onDisposal = $this->onDisposal;
        $this->onDisposal = null;

        $this->relieveBackPressure($this->exception);
        $this->resolvePending();

        foreach ($onDisposal as $callback) {
            Loop::queue($callback, $this->exception);
        }
    }
}
