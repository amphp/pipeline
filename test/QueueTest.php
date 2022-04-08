<?php

namespace Amp\Pipeline;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class QueueTest extends AsyncTestCase
{
    private Queue $queue;

    public function setUp(): void
    {
        parent::setUp();
        $this->queue = new Queue;
    }

    public function testEmit(): void
    {
        $value = 'Emitted Value';

        $future = $this->queue->pushAsync($value);
        $iterator = $this->queue->iterate();

        self::assertFalse($future->isComplete());
        self::assertTrue($iterator->continue());
        self::assertSame($value, $iterator->getValue());
        self::assertTrue($future->isComplete());

        // Future will not complete until another value is enqueued or iterator completed.
        $continue = async(fn () => $iterator->continue());

        self::assertInstanceOf(Future::class, $future);
        self::assertNull($future->await());

        self::assertFalse($this->queue->isComplete());
        self::assertFalse($iterator->isComplete());

        $this->queue->complete();

        self::assertTrue($this->queue->isComplete());
        self::assertTrue($iterator->isComplete());

        self::assertFalse($continue->await());
    }

    public function testFail(): void
    {
        self::assertFalse($this->queue->isComplete());
        $this->queue->error($exception = new \Exception);
        self::assertTrue($this->queue->isComplete());

        $pipeline = $this->queue->pipe()->getIterator();
        self::assertTrue($pipeline->isComplete());

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
        $this->expectExceptionMessage('Values cannot be enqueued after calling complete');

        $this->queue->complete();
        $this->queue->pushAsync(1)->await();
    }

    /**
     * @depends testEmit
     */
    public function testEmittingNull(): void
    {
        $this->queue->pushAsync(null);

        $pipe = $this->queue->pipe()->getIterator();
        self::assertTrue($pipe->continue());
        self::assertNull($pipe->getValue());
    }

    public function testDoubleComplete(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Queue has already been completed');

        $this->queue->complete();
        $this->queue->complete();
    }

    public function testDoubleFail(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Queue has already been completed');

        $this->queue->error(new \Exception);
        $this->queue->error(new \Exception);
    }

    public function testDoubleStart(): void
    {
        $this->queue->pipe();
        $this->queue->pipe();

        $this->expectNotToPerformAssertions();
    }

    public function testEmitAfterContinue(): void
    {
        $value = 'Emitted Value';

        $pipeline = $this->queue->pipe()->getIterator();

        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        delay(0); // Enter async function above.

        $backPressure = $this->queue->pushAsync($value);

        self::assertSame($value, $future->await());

        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        $future->ignore();

        self::assertNull($backPressure->await());

        self::assertFalse($pipeline->isComplete());

        $this->queue->complete();

        self::assertTrue($pipeline->isComplete());
    }

    public function testContinueAfterComplete(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();

        $this->queue->complete();

        self::assertFalse($pipeline->continue());
    }

    public function testContinueAfterFail(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();

        $this->queue->error(new \Exception('Pipeline failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pipeline failed');

        $pipeline->continue();
    }

    public function testCompleteAfterContinue(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();

        $future = async(fn () => $pipeline->continue());
        self::assertInstanceOf(Future::class, $future);

        $this->queue->complete();

        self::assertFalse($future->await());
    }

    public function testDestroyingPipelineRelievesBackPressure(): void
    {
        $pipeline = $this->queue->pipe();

        $invoked = 0;
        foreach (\range(1, 5) as $value) {
            $future = $this->queue->pushAsync($value);
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

        $this->queue->complete(); // Should not throw.

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Queue has already been completed');

        $this->queue->complete(); // Should throw.
    }

    public function testEmitAfterDisposal(): void
    {
        $pipeline = $this->queue->pipe();
        $pipeline->dispose();
        self::assertTrue($pipeline->getIterator()->isComplete());
        self::assertTrue($this->queue->isDisposed());

        try {
            $this->queue->pushAsync(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposal(): void
    {
        $pipeline = $this->queue->pipe();
        unset($pipeline); // Trigger automatic disposal.
        self::assertTrue($this->queue->isDisposed());

        try {
            $this->queue->pushAsync(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposalAfterDelay(): void
    {
        $pipeline = $this->queue->pipe();
        unset($pipeline); // Trigger automatic disposal.
        self::assertTrue($this->queue->isDisposed());

        delay(0.01);

        try {
            $this->queue->pushAsync(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposalWithPendingContinueFuture(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();
        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });

        unset($pipeline); // Trigger automatic disposal.
        self::assertFalse($this->queue->isDisposed());
        $this->queue->pushAsync(1)->ignore();
        self::assertSame(1, $future->await());

        self::assertTrue($this->queue->isDisposed());

        try {
            $this->queue->pushAsync(2)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterExplicitDisposalWithPendingContinueFuture(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();
        $future = async(function () use ($pipeline) {
            $pipeline->continue();
            return $pipeline->getValue();
        });
        $pipeline->dispose();
        self::assertTrue($this->queue->isDisposed());

        try {
            $future->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterDestruct(): void
    {
        $pipeline = $this->queue->pipe();
        $future = $this->queue->pushAsync(1);
        unset($pipeline);
        self::assertTrue($this->queue->isDisposed());
        $future->ignore();

        try {
            $this->queue->pushAsync(2)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testFailWithDisposedException(): void
    {
        // Using DisposedException, but should be treated as fail, not disposal.
        $this->queue->error(new DisposedException);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Queue has already been completed');

        $this->queue->complete();
    }

    public function testTraversable(): void
    {
        EventLoop::queue(function (): void {
            try {
                $this->queue->push(1);
                $this->queue->push(2);
                $this->queue->push(3);
                $this->queue->complete();
            } catch (\Throwable $exception) {
                $this->queue->error($exception);
            }
        });

        $values = [];

        foreach ($this->queue->pipe() as $value) {
            $values[] = $value;
        }

        self::assertSame([1, 2, 3], $values);
    }

    public function testBackPressureOnComplete(): void
    {
        $future1 = $this->queue->pushAsync(1);
        $future2 = $this->queue->pushAsync(2);
        $this->queue->complete();

        delay(0.01);

        self::assertFalse($future1->isComplete());

        $pipeline = $this->queue->pipe()->getIterator();

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
        $future1 = $this->queue->pushAsync(1);
        $future2 = $this->queue->pushAsync(2);

        $future1->ignore();
        $future2->ignore();

        delay(0.01);

        self::assertFalse($future1->isComplete());

        $pipeline = $this->queue->pipe();
        unset($pipeline);

        delay(0.01);

        self::assertTrue($future1->isComplete());
        self::assertTrue($future2->isComplete());
    }

    public function testCancellation(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();

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

        $this->queue->pushAsync(1);
        $this->queue->pushAsync(2);
        $this->queue->pushAsync(3);

        self::assertSame(1, $future1->await());
        self::assertSame(2, $future3->await());
        self::assertSame(3, $future4->await());

        $this->queue->complete();

        $this->expectException(CancelledException::class);
        $future2->await();
    }

    public function testCancellationThenEmitAdditionalValues(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();

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

        $this->queue->pushAsync(1);
        $this->queue->pushAsync(2);
        $this->queue->pushAsync(3);

        self::assertSame(1, $future1->await());

        self::assertTrue($pipeline->continue());
        self::assertSame(2, $pipeline->getValue());

        self::assertTrue($pipeline->continue());
        self::assertSame(3, $pipeline->getValue());

        $this->queue->complete();

        $this->expectException(CancelledException::class);
        $future2->await();
    }

    public function testCancellationAfterEmitted(): void
    {
        $pipeline = $this->queue->pipe()->getIterator();

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

        $this->queue->pushAsync(1);
        $this->queue->pushAsync(2);
        $this->queue->pushAsync(3);

        $cancellation->cancel();

        delay(0); // Tick event loop to trigger cancellation callback.

        self::assertSame(1, $future1->await());
        self::assertSame(2, $future2->await());
        self::assertSame(3, $future3->await());

        $this->queue->complete();

        self::assertNull($future4->await());
    }

    /**
     * @dataProvider provideBufferSize
     */
    public function testBufferSize(int $bufferSize): void
    {
        $source = new Queue($bufferSize);
        $pipeline = $source->pipe()->getIterator();

        for ($i = 0; $i < $bufferSize; $i++) {
            self::assertTrue($source->pushAsync('.')->isComplete());
        }

        $blocked = $source->pushAsync('x');
        self::assertFalse($blocked->isComplete());

        self::assertTrue($pipeline->continue());
        self::assertTrue($blocked->isComplete());

        self::assertFalse($source->pushAsync('x')->isComplete());

        if ($bufferSize === 0) {
            return;
        }

        self::assertTrue($pipeline->continue());
        self::assertTrue($pipeline->continue());

        self::assertTrue($source->pushAsync('.')->isComplete());
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
