<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Internal\ConcurrentClosureIterator;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class ConcurrentClosureIteratorTest extends AsyncTestCase
{
    public function test(): void
    {
        $iterator = new ConcurrentClosureIterator(function ($cancellation) {
            static $i = 0;

            $i++;

            delay(0.5, cancellation: $cancellation);

            return $i;
        });

        try {
            $iterator->continue(new TimeoutCancellation(0.05));
            self::fail('Should throw exception');
        } catch (CancelledException) {
        }

        self::assertTrue($iterator->continue(new TimeoutCancellation(1)));
        self::assertSame(1, $iterator->getValue());
        self::assertSame(0, $iterator->getPosition());

        self::assertTrue($iterator->continue(new TimeoutCancellation(1)));
        self::assertSame(2, $iterator->getValue());
        self::assertSame(1, $iterator->getPosition());
    }

    public function testConcurrentContinueWhileSourceAwaitsSequence(): void
    {
        $invocations = 0;
        $iterator = new ConcurrentClosureIterator(function () use (&$invocations): int {
            $i = $invocations++;
            if ($i === 0) {
                delay(0.1); // First invocation is slow, subsequent invocations return immediately.
            }

            return $i;
        });

        $consume = static fn () => $iterator->continue() ? $iterator->getValue() : null;

        $futures = [];
        $futures[] = async($consume);
        $futures[] = async($consume);

        delay(0.05); // Allow the second supplier fiber to block on the ordering sequence.

        $futures[] = async($consume);

        self::assertSame([0, 1, 2], Future\await($futures));
        self::assertSame(3, $invocations);
    }

    public function testCancellationInSameTickAsValueDelivery(): void
    {
        $invocations = 0;
        $iterator = new ConcurrentClosureIterator(function () use (&$invocations): int {
            return $invocations++;
        });

        $deferredCancellation = new DeferredCancellation();

        $future = async(static fn () => $iterator->continue($deferredCancellation->getCancellation())
            ? $iterator->getValue()
            : null);

        // Cancellation callbacks are queued in the same tick the first value is delivered.
        EventLoop::queue(static fn () => $deferredCancellation->cancel());

        self::assertSame(0, $future->await());

        self::assertTrue($iterator->continue(new TimeoutCancellation(1)));
        self::assertSame(1, $iterator->getValue());
    }

    public function testCancelledContinueAfterCancelledContinue(): void
    {
        $invocations = 0;
        $iterator = new ConcurrentClosureIterator(function () use (&$invocations): int {
            $i = $invocations++;

            delay(0.1);

            return $i;
        });

        try {
            $iterator->continue(new TimeoutCancellation(0.02));
            self::fail('Should throw exception');
        } catch (CancelledException) {
        }

        try {
            $iterator->continue(new TimeoutCancellation(0.02));
            self::fail('Should throw exception');
        } catch (CancelledException) {
        }

        self::assertTrue($iterator->continue());
        self::assertSame(0, $iterator->getValue());
        self::assertSame(1, $invocations);
    }

    public function testDisposeWhileSourceSuspendedInPush(): void
    {
        $iterator = new ConcurrentClosureIterator(function (): int {
            delay(0.05);

            return 1;
        });

        try {
            $iterator->continue(new TimeoutCancellation(0.01));
            self::fail('Should throw exception');
        } catch (CancelledException) {
        }

        delay(0.1); // Allow the supplier fiber to suspend within push() awaiting backpressure.

        $iterator->dispose();

        delay(0.1); // Allow the supplier fiber to be resumed with the disposal exception.

        self::assertTrue($iterator->isComplete());
    }

    public function testDisposeBeforeConsume(): void
    {
        $iterator = new ConcurrentClosureIterator(fn () => 1);

        $iterator->dispose();

        delay(0.01); // Allow the cancellation callback to run on the event loop.

        self::assertTrue($iterator->isComplete());
    }

    public function testDisposeAfterConsume(): void
    {
        $iterator = new ConcurrentClosureIterator(function (): int {
            static $i = 0;

            return ++$i;
        });

        self::assertTrue($iterator->continue());
        self::assertSame(1, $iterator->getValue());

        $iterator->dispose();

        delay(0.01); // Allow the cancellation callback to run on the event loop.

        self::assertTrue($iterator->isComplete());
    }
}
