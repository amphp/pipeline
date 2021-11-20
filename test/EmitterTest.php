<?php

namespace Amp\Pipeline;

use Amp\CancellationTokenSource;
use Amp\CancelledException;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Revolt\EventLoop;
use function Amp\delay;
use function Amp\launch;

class EmitterTest extends AsyncTestCase
{
    /** @var Emitter */
    private Emitter $source;

    public function setUp(): void
    {
        parent::setUp();
        $this->source = new Emitter;
    }

    public function testEmit(): void
    {
        $value = 'Emited Value';

        $future = $this->source->emit($value);
        $pipeline = $this->source->asPipeline();

        self::assertSame($value, $pipeline->continue());

        $continue = launch(fn (
        ) => $pipeline->continue()); // Promise will not resolve until another value is emitted or pipeline completed.

        self::assertInstanceOf(Future::class, $future);
        self::assertNull($future->await());

        self::assertFalse($this->source->isComplete());

        $this->source->complete();

        self::assertTrue($this->source->isComplete());

        self::assertNull($continue->await());
    }

    public function testFail(): void
    {
        self::assertFalse($this->source->isComplete());
        $this->source->error($exception = new \Exception);
        self::assertTrue($this->source->isComplete());

        $pipeline = $this->source->asPipeline();

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
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Pipelines cannot emit NULL');

        $this->source->emit(null)->await();
    }

    /**
     * @depends testEmit
     */
    public function testEmittingFuture(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Pipelines cannot emit futures');

        $this->source->emit(Future::complete(null))->await();
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
        $pipeline = $this->source->asPipeline();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('A pipeline may be started only once');

        $pipeline = $this->source->asPipeline();
    }

    public function testEmitAfterContinue(): void
    {
        $value = 'Emited Value';

        $pipeline = $this->source->asPipeline();

        $future = launch(fn () => $pipeline->continue());

        $backPressure = $this->source->emit($value);

        self::assertSame($value, $future->await());

        $future = launch(fn () => $pipeline->continue());
        $future->ignore();

        self::assertNull($backPressure->await());

        $this->source->complete();
    }

    public function testContinueAfterComplete(): void
    {
        $pipeline = $this->source->asPipeline();

        $this->source->complete();

        self::assertNull($pipeline->continue());
    }

    public function testContinueAfterFail(): void
    {
        $pipeline = $this->source->asPipeline();

        $this->source->error(new \Exception('Pipeline failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pipeline failed');

        $pipeline->continue();
    }

    public function testCompleteAfterContinue(): void
    {
        $pipeline = $this->source->asPipeline();

        $future = launch(fn () => $pipeline->continue());
        self::assertInstanceOf(Future::class, $future);

        $this->source->complete();

        self::assertNull($future->await());
    }

    public function testDestroyingPipelineRelievesBackPressure(): void
    {
        $pipeline = $this->source->asPipeline();

        $invoked = 0;
        foreach (\range(1, 5) as $value) {
            $future = $this->source->emit($value);
            EventLoop::queue(function () use (&$invoked, $future): void {
                try {
                    $future->await();
                } catch (DisposedException $exception) {
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

    public function testOnDisposal(): void
    {
        $invoked = false;
        $this->source->onDisposal(function () use (&$invoked) {
            $invoked = true;
        });

        self::assertFalse($invoked);

        $pipeline = $this->source->asPipeline();
        $pipeline->dispose();

        delay(0);

        self::assertTrue($invoked);

        $this->source->onDisposal($this->createCallback(1));

        delay(0);
    }

    public function testOnDisposalAfterCompletion(): void
    {
        $invoked = false;
        $this->source->onDisposal(function () use (&$invoked) {
            $invoked = true;
        });

        self::assertFalse($invoked);

        $this->source->complete();

        $pipeline = $this->source->asPipeline();
        $pipeline->dispose();

        self::assertFalse($invoked);

        $this->source->onDisposal($this->createCallback(0));

        delay(0);
    }

    public function testEmitAfterDisposal(): void
    {
        $pipeline = $this->source->asPipeline();
        $this->source->onDisposal($this->createCallback(1));
        $pipeline->dispose();
        $this->source->onDisposal($this->createCallback(1));
        self::assertTrue($this->source->isDisposed());

        try {
            $this->source->emit(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException $exception) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposal(): void
    {
        $pipeline = $this->source->asPipeline();
        $this->source->onDisposal($this->createCallback(1));
        unset($pipeline); // Trigger automatic disposal.
        $this->source->onDisposal($this->createCallback(1));
        self::assertTrue($this->source->isDisposed());

        try {
            $this->source->emit(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException $exception) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposalAfterDelay(): void
    {
        $pipeline = $this->source->asPipeline();
        $this->source->onDisposal($this->createCallback(1));
        unset($pipeline); // Trigger automatic disposal.
        $this->source->onDisposal($this->createCallback(1));
        self::assertTrue($this->source->isDisposed());

        delay(0.01);

        try {
            $this->source->emit(1)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException $exception) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterAutomaticDisposalWithPendingContinueFuture(): void
    {
        $pipeline = $this->source->asPipeline();
        $future = launch(fn () => $pipeline->continue());
        $this->source->onDisposal($this->createCallback(1));
        unset($pipeline); // Trigger automatic disposal.
        $this->source->onDisposal($this->createCallback(1));
        self::assertFalse($this->source->isDisposed());
        $this->source->emit(1)->ignore();
        self::assertSame(1, $future->await());

        self::assertTrue($this->source->isDisposed());

        try {
            $this->source->emit(2)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException $exception) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterExplicitDisposalWithPendingContinueFuture(): void
    {
        $pipeline = $this->source->asPipeline();
        $future = launch(fn () => $pipeline->continue());
        $this->source->onDisposal($this->createCallback(1));
        $pipeline->dispose();
        $this->source->onDisposal($this->createCallback(1));
        self::assertTrue($this->source->isDisposed());

        try {
            $future->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException $exception) {
            delay(0); // Trigger disposal callback in defer.
        }
    }

    public function testEmitAfterDestruct(): void
    {
        $pipeline = $this->source->asPipeline();
        $future = $this->source->emit(1);
        $this->source->onDisposal($this->createCallback(1));
        unset($pipeline);
        $this->source->onDisposal($this->createCallback(1));
        self::assertTrue($this->source->isDisposed());
        $future->ignore();

        try {
            $this->source->emit(2)->await();
            $this->fail(\sprintf('Expected instance of %s to be thrown', DisposedException::class));
        } catch (DisposedException $exception) {
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

        foreach ($this->source->asPipeline() as $value) {
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

        $pipeline = $this->source->asPipeline();
        self::assertSame(1, $pipeline->continue());
        self::assertSame(2, $pipeline->continue());


        self::assertTrue($future1->isComplete());
        self::assertFalse($future2->isComplete());

        self::assertSame(null, $pipeline->continue());

        delay(0.01);

        self::assertTrue($future2->isComplete());
    }

    public function testBackPressureOnDisposal(): void
    {
        $future1 = $this->source->emit(1);
        $future2 = $this->source->emit(2);

        $future1->ignore();
        $future2->ignore();

        delay(0.01);

        self::assertFalse($future1->isComplete());

        $pipeline = $this->source->asPipeline();
        unset($pipeline);

        delay(0.01);

        self::assertTrue($future1->isComplete());
        self::assertTrue($future2->isComplete());
    }

    public function testCancellation(): void
    {
        $pipeline = $this->source->asPipeline();

        $tokenSource = new CancellationTokenSource();

        $future1 = launch(fn () => $pipeline->continue());
        $future2 = launch(fn () => $pipeline->continue($tokenSource->getToken()));
        $future3 = launch(fn () => $pipeline->continue());
        $future4 = launch(fn () => $pipeline->continue());

        $tokenSource->cancel();

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

    public function testCancellationAfterEmitted(): void
    {
        $pipeline = $this->source->asPipeline();

        $tokenSource = new CancellationTokenSource();

        $future1 = launch(fn () => $pipeline->continue());
        $future2 = launch(fn () => $pipeline->continue($tokenSource->getToken()));
        $future3 = launch(fn () => $pipeline->continue());
        $future4 = launch(fn () => $pipeline->continue());

        $this->source->emit(1);
        $this->source->emit(2);
        $this->source->emit(3);

        $tokenSource->cancel();

        delay(0); // Tick event loop to trigger cancellation callback.

        self::assertSame(1, $future1->await());
        self::assertSame(2, $future2->await());
        self::assertSame(3, $future3->await());

        $this->source->complete();

        self::assertNull($future4->await());
    }
}
