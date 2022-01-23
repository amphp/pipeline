<?php

namespace Amp\Pipeline;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class EmitterTest extends AsyncTestCase
{
    private Emitter $source;

    public function setUp(): void
    {
        parent::setUp();
        $this->source = new Emitter;
    }

    public function testEmit(): void
    {
        $value = 'Emitted Value';

        $future = $this->source->emit($value);
        $pipeline = $this->source->pipe()->getIterator();

        self::assertFalse($future->isComplete());
        self::assertTrue($pipeline->continue());
        self::assertSame($value, $pipeline->getValue());
        self::assertTrue($future->isComplete());

        // Future will not complete until another value is emitted or pipeline completed.
        $continue = async(fn () => $pipeline->continue());

        self::assertInstanceOf(Future::class, $future);
        self::assertNull($future->await());

        self::assertFalse($this->source->isComplete());

        $this->source->complete();

        self::assertTrue($this->source->isComplete());

        self::assertFalse($continue->await());
    }

    public function testFail(): void
    {
        self::assertFalse($this->source->isComplete());
        $this->source->error($exception = new \Exception);
        self::assertTrue($this->source->isComplete());

        $pipeline = $this->source->pipe()->getIterator();

        try {
            $pipeline->continue();
        } catch (\Exception $caught) {
            self::assertSame($exception, $caught);
        }
    }

    /**
     * @depends testEmit
     */
    public function testEmitAfterComplete(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pipelines cannot emit values after calling complete');

        $this->source->complete();
        $this->source->emit(1)->await();
    }

    /**
     * @depends testEmit
     */
    public function testEmittingNull(): void
    {
        $this->source->emit(null);

        $pipe = $this->source->pipe()->getIterator();
        self::assertTrue($pipe->continue());
        self::assertNull($pipe->getValue());
    }

    public function testDoubleComplete(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pipeline has already been completed');

        $this->source->complete();
        $this->source->complete();
    }

    public function testDoubleFail(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pipeline has already been completed');

        $this->source->error(new \Exception);
        $this->source->error(new \Exception);
    }

    public function testDoubleStart(): void
    {
        $this->source->pipe();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('A pipeline may be started only once');

        $this->source->pipe();
    }

    public function testEmitAfterContinue(): void
    {
        $value = 'Emitted Value';

        $pipeline = $this->source->pipe()->getIterator();

        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $backPressure = $this->source->emit($value);

        self::assertSame($value, $future->await());

        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $future->ignore();

        self::assertNull($backPressure->await());

        $this->source->complete();
    }

    public function testContinueAfterComplete(): void
    {
        $pipeline = $this->source->pipe()->getIterator();

        $this->source->complete();

        self::assertFalse($pipeline->continue());
    }

    public function testContinueAfterFail(): void
    {
        $pipeline = $this->source->pipe()->getIterator();

        $this->source->error(new \Exception('Pipeline failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pipeline failed');

        $pipeline->continue();
    }

    public function testCompleteAfterContinue(): void
    {
        $pipeline = $this->source->pipe()->getIterator();

        $future = async(fn () => $pipeline->continue());
        self::assertInstanceOf(Future::class, $future);

        $this->source->complete();

        self::assertFalse($future->await());
    }

    public function testDestroyingPipelineRelievesBackPressure(): void
    {
        $pipeline = $this->source->pipe();

        $invoked = 0;
        foreach (\range(1, 5) as $value) {
            $future = $this->source->emit($value);
            EventLoop::queue(static function () use (&$invoked, $future): void {
                try {
                    $future->await();
                } catch (DisposedException) {
                    // Ignore disposal.
                } finally {
                    $invoked++;
                }
            });
        }

        self::assertSame(0, $invoked);

        unset($pipeline); // Should relieve all back-pressure.

        delay(0.005); // Tick event loop to invoke future callbacks.

        self::assertSame(5, $invoked);

        $this->source->complete(); // Should not throw.

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pipeline has already been completed');

        $this->source->complete(); // Should throw.
    }

    public function testEmitAfterDisposal(): void
    {
        $pipeline = $this->source->pipe();
        $pipeline->dispose();
        self::assertTrue($this->source->isDisposed());

        try {
            $this->source->emit(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposal(): void
    {
        $pipeline = $this->source->pipe();
        unset($pipeline); // Trigger automatic disposal.
        self::assertTrue($this->source->isDisposed());

        try {
            $this->source->emit(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposalAfterDelay(): void
    {
        $pipeline = $this->source->pipe();
        unset($pipeline); // Trigger automatic disposal.
        self::assertTrue($this->source->isDisposed());

        delay(0.01);

        try {
            $this->source->emit(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposalWithPendingContinueFuture(): void
    {
        $pipeline = $this->source->pipe()->getIterator();
        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        unset($pipeline); // Trigger automatic disposal.
        self::assertFalse($this->source->isDisposed());
        $this->source->emit(1)->ignore();
        self::assertSame(1, $future->await());

        self::assertTrue($this->source->isDisposed());

        try {
            $this->source->emit(2)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterExplicitDisposalWithPendingContinueFuture(): void
    {
        $pipeline = $this->source->pipe()->getIterator();
        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });
        $pipeline->dispose();
        self::assertTrue($this->source->isDisposed());

        try {
            $future->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterDestruct(): void
    {
        $pipeline = $this->source->pipe();
        $future = $this->source->emit(1);
        unset($pipeline);
        self::assertTrue($this->source->isDisposed());
        $future->ignore();

        try {
            $this->source->emit(2)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testFailWithDisposedException(): void
    {
        // Using DisposedException, but should be treated as fail, not disposal.
        $this->source->error(new DisposedException);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Pipeline has already been completed');

        $this->source->complete();
    }

    public function testTraversable(): void
    {
        EventLoop::queue(function (): void {
            try {
                $this->source->yield(1);
                $this->source->yield(2);
                $this->source->yield(3);
                $this->source->complete();
            } catch (\Throwable $exception) {
                $this->source->error($exception);
            }
        });

        $values = [];

        foreach ($this->source->pipe() as $value) {
            $values[] = $value;
        }

        self::assertSame([1, 2, 3], $values);
    }

    public function testBackPressureOnComplete(): void
    {
        $future1 = $this->source->emit(1);
        $future2 = $this->source->emit(2);
        $this->source->complete();

        delay(0.01);

        self::assertFalse($future1->isComplete());

        $pipeline = $this->source->pipe()->getIterator();

        self::assertTrue($pipeline->continue());
        self::assertSame(1, $pipeline->getValue());
        self::assertTrue($future1->isComplete());
        self::assertFalse($future2->isComplete());

        self::assertTrue($pipeline->continue());
        self::assertSame(2, $pipeline->getValue());
        self::assertTrue($future1->isComplete());
        self::assertTrue($future2->isComplete());

        self::assertFalse($pipeline->continue());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('complete');

        $pipeline->getValue();
    }

    public function testBackPressureOnDisposal(): void
    {
        $future1 = $this->source->emit(1);
        $future2 = $this->source->emit(2);

        $future1->ignore();
        $future2->ignore();

        delay(0.01);

        self::assertFalse($future1->isComplete());

        $pipeline = $this->source->pipe();
        unset($pipeline);

        delay(0.01);

        self::assertTrue($future1->isComplete());
        self::assertTrue($future2->isComplete());
    }

    public function testCancellation(): void
    {
        $pipeline = $this->source->pipe()->getIterator();

        $cancellation = new DeferredCancellation();

        $future1 = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $future2 = async(function () use ($pipeline, $cancellation) {
            $pipeline->continue($cancellation->getCancellation());
            return $pipeline->getValue();
        });

        $future3 = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $future4 = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $cancellation->cancel();

        delay(0); // Tick event loop to trigger cancellation callback.

        $this->source->emit(1);
        $this->source->emit(2);
        $this->source->emit(3);

        self::assertSame(1, $future1->await());
        self::assertSame(2, $future3->await());
        self::assertSame(3, $future4->await());

        $this->source->complete();

        $this->expectException(CancelledException::class);
        $future2->await();
    }

    public function testCancellationThenEmitAdditionalValues(): void
    {
        $pipeline = $this->source->pipe()->getIterator();

        $cancellation = new DeferredCancellation();

        $future1 = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $future2 = async(function () use ($pipeline, $cancellation) {
            $pipeline->continue($cancellation->getCancellation());
            return $pipeline->getValue();
        });

        $cancellation->cancel();

        delay(0); // Tick event loop to trigger cancellation callback.

        $this->source->emit(1);
        $this->source->emit(2);
        $this->source->emit(3);

        self::assertSame(1, $future1->await());

        self::assertTrue($pipeline->continue());
        self::assertSame(2, $pipeline->getValue());

        self::assertTrue($pipeline->continue());
        self::assertSame(3, $pipeline->getValue());

        $this->source->complete();

        $this->expectException(CancelledException::class);
        $future2->await();
    }

    public function testCancellationAfterEmitted(): void
    {
        $pipeline = $this->source->pipe()->getIterator();

        $cancellation = new DeferredCancellation();

        $future1 = async(function () use ($pipeline) {
            self::assertTrue($pipeline->continue());
            return $pipeline->getValue();
        });

        $future2 = async(function () use ($pipeline, $cancellation) {
            self::assertTrue($pipeline->continue($cancellation->getCancellation()));
            return $pipeline->getValue();
        });

        $future3 = async(function () use ($pipeline) {
            self::assertTrue($pipeline->continue());
            return $pipeline->getValue();
        });

        $future4 = async(function () use ($pipeline) {
            self::assertFalse($pipeline->continue());
            return null;
        });

        $this->source->emit(1);
        $this->source->emit(2);
        $this->source->emit(3);

        $cancellation->cancel();

        delay(0); // Tick event loop to trigger cancellation callback.

        self::assertSame(1, $future1->await());
        self::assertSame(2, $future2->await());
        self::assertSame(3, $future3->await());

        $this->source->complete();

        self::assertNull($future4->await());
    }

    /**
     * @dataProvider provideBufferSize
     */
    public function testBufferSize(int $bufferSize): void
    {
        $source = new Emitter($bufferSize);
        $pipeline = $source->pipe()->getIterator();

        for ($i = 0; $i < $bufferSize; $i++) {
            self::assertTrue($source->emit('.')->isComplete());
        }

        $blocked = $source->emit('x');
        self::assertFalse($blocked->isComplete());

        self::assertTrue($pipeline->continue());
        self::assertTrue($blocked->isComplete());

        self::assertFalse($source->emit('x')->isComplete());

        if ($bufferSize === 0) {
            return;
        }

        self::assertTrue($pipeline->continue());
        self::assertTrue($pipeline->continue());

        self::assertTrue($source->emit('.')->isComplete());
    }

    public function provideBufferSize(): iterable
    {
        yield 'buffer size 0' => [0];
        yield 'buffer size 1' => [1];
        yield 'buffer size 2' => [2];
        yield 'buffer size 5' => [5];
        yield 'buffer size 10' => [10];
    }
}
