<?php

namespace Amp\Pipeline\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Internal;
use Amp\Pipeline\DisposedException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * Class used internally by {@see Pipeline} implementations. Do not use this class in your code, instead compose your
 * class from one of the available classes implementing {@see Pipeline}.
 *
 * @internal
 *
 * @template TValue
 */
final class EmitSource
{
    private const CONTINUE = [null];

    private bool $completed = false;

    private ?\Throwable $exception = null;

    /** @var array<int, TValue> */
    private array $emittedValues = [];

    /** @var array<int, DeferredFuture|Suspension> */
    private array $backPressure = [];

    /** @var Suspension[] */
    private array $waiting = [];

    private int $consumePosition = 0;

    private int $emitPosition = 0;

    private ?array $resolutionTrace = null;

    private bool $disposed = false;

    /**
     * @return TValue
     */
    public function continue(?Cancellation $cancellation = null): mixed
    {
        $position = $this->consumePosition++;

        // Relieve backpressure from prior emit.
        if (isset($this->backPressure[$position])) {
            $placeholder = $this->backPressure[$position];
            unset($this->backPressure[$position]);
            $placeholder instanceof Suspension
                ? $placeholder->resume()
                : $placeholder->complete();
        }

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
        $this->waiting[] = $suspension = EventLoop::createSuspension();

        if ($cancellation) {
            $waiting = &$this->waiting;
            $id = $cancellation->subscribe(static function (\Throwable $exception) use (
                &$waiting,
                $suspension
            ): void {
                foreach ($waiting as $key => $pending) {
                    if ($pending === $suspension) {
                        unset($waiting[$key]);
                        $suspension->throw($exception);
                        return;
                    }
                }
            });
        }

        try {
            return $suspension->suspend();
        } finally {
            /** @psalm-suppress PossiblyUndefinedVariable $id will be defined if $cancellation is not null. */
            $cancellation?->unsubscribe($id);
        }
    }

    /**
     * @return void
     *
     * @see Pipeline::dispose()
     */
    public function dispose(): void
    {
        try {
            if ($this->completed || $this->disposed) {
                return; // Pipeline already completed or failed.
            }

            $this->finalize(new DisposedException, true);
        } finally {
            if ($this->disposed) {
                $this->triggerDisposal();
            }
        }
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

        if (!empty($this->waiting)) {
            $key = \array_key_first($this->waiting);
            $suspension = $this->waiting[$key];
            unset($this->waiting[$key]);
            $suspension->resume($value);

            if ($this->disposed && empty($this->waiting)) {
                $this->triggerDisposal();
                return self::CONTINUE; // Subsequent push() calls will throw.
            }

            if ($this->consumePosition > $position) {
                return self::CONTINUE;
            }

            return null;
        }

        if ($this->disposed) {
            \assert(isset($this->exception), "Failure exception must be set when disposed");
            // Pipeline has been disposed and no Fibers are still pending.
            return [$this->exception];
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
     * @return Future Resolves once the value has been consumed on the pipeline.
     */
    public function emit(mixed $value): Future
    {
        $position = $this->emitPosition++;
        $next = $this->push($value, $position);

        if ($next === null) {
            $this->backPressure[$position] = $deferred = new DeferredFuture;
            return $deferred->getFuture();
        }

        [$exception] = $next;

        if ($exception) {
            return Future::error($exception);
        }

        return Future::complete();
    }

    /**
     * Emits a value from the pipeline, suspending execution until the value is consumed.
     *
     * @param TValue $value Value to emit from the pipeline.
     */
    public function yield(mixed $value): void
    {
        $position = $this->emitPosition++;
        $next = $this->push($value, $position);

        if ($next === null) {
            $this->backPressure[$position] = $suspension = EventLoop::createSuspension();
            $suspension->suspend();
            return;
        }

        [$exception] = $next;

        if ($exception) {
            throw $exception;
        }
    }

    /**
     * @return bool True if the pipeline has been completed or failed.
     */
    public function isComplete(): bool
    {
        return $this->completed;
    }

    /**
     * @return bool True if the pipeline has no values pending and has been completed.
     */
    public function isConsumed(): bool
    {
        return empty($this->emittedValues) && $this->completed;
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
                    ? $placeholder->resume()
                    : $placeholder->complete();
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
                $suspension->resume();
            }
        }
    }

    /**
     * Fails pending {@see continue()} promises.
     */
    private function triggerDisposal(): void
    {
        \assert($this->disposed && $this->exception, "Pipeline was not disposed on triggering disposal");

        /** @psalm-suppress RedundantCondition */
        if (isset($this->backPressure)) {
            $this->relieveBackPressure($this->exception);
        }

        /** @psalm-suppress RedundantCondition */
        if (isset($this->waiting)) {
            $this->resolvePending();
        }
    }
}
