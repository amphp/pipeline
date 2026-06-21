<?php declare(strict_types=1);

namespace Amp\Pipeline;

use Amp\CancelledException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Internal\ConcurrentClosureIterator;
use Amp\TimeoutCancellation;
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
