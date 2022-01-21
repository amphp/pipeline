<?php

namespace Amp\Pipeline;

use Amp\DeferredFuture;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\delay;
use function Amp\now;

class FromIterableGeneratorTest extends AsyncTestCase
{
    private const TIMEOUT = 0.1;

    public function testNonGeneratorClosure(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Return value of argument #1 ($iterable) must be of type iterable, null returned');

        fromIterable(static fn () => null);
    }

    public function testThrowingClosure(): void
    {
        $exception = new \Exception;

        $this->expectExceptionObject($exception);

        fromIterable(static fn () => throw $exception);
    }

    public function testYield(): void
    {
        $value = 1;

        $generator = fromIterable(function () use ($value) {
            yield $value;
        });

        self::assertSame([1], $generator->toArray());
    }

    /**
     * @depends testYield
     */
    public function testFailingPromise(): void
    {
        $exception = new TestException;
        $deferred = new DeferredFuture;

        $generator = fromIterable(function () use ($deferred) {
            yield $deferred->getFuture()->await();
        });

        $deferred->error($exception);

        try {
            $generator->toArray();
            self::fail("Awaiting a failed future should fail the pipeline");
        } catch (TestException $reason) {
            self::assertSame($reason, $exception);
        }
    }

    /**
     * @depends testYield
     */
    public function testBackPressure(): void
    {
        $output = '';
        $yields = 5;

        $generator = fromIterable(function () use (&$time, $yields) {
            $time = now();
            for ($i = 0; $i < $yields; ++$i) {
                yield $i;
            }
            $time = now() - $time;
        });

        $iterator = $generator->getIterator();
        while ($iterator->continue()) {
            $output .= $iterator->get();
            delay(self::TIMEOUT);
        }

        $expected = \implode('', \range(0, $yields - 1));

        self::assertSame($expected, $output);
        self::assertGreaterThan(self::TIMEOUT * ($yields - 1), $time * 1000);
    }

    /**
     * @depends testYield
     */
    public function testGeneratorThrows(): void
    {
        $exception = new TestException;

        try {
            $generator = fromIterable(function () use ($exception) {
                yield 1;
                throw $exception;
            });

            $generator->toArray();

            self::fail("The exception thrown from the generator should fail the pipeline");
        } catch (TestException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function testDisposal(): void
    {
        $invoked = false;
        $generator = fromIterable(function () use (&$invoked) {
            try {
                yield 0;
                yield 1;
            } finally {
                $invoked = true;
            }
        });

        $iterator = $generator->getIterator();
        self::assertTrue($iterator->continue());
        self::assertSame(0, $iterator->get());

        self::assertFalse($invoked);

        $iterator->dispose();

        delay(0); // Tick event loop to destroy generator.

        try {
            $iterator->continue();
            $iterator->continue();
            self::fail("Pipeline should have been disposed");
        } catch (DisposedException) {
            self::assertTrue($invoked);
        }
    }

    /**
     * @depends testDisposal
     */
    public function testYieldAfterDisposal(): void
    {
        $generator = fromIterable(function () {
            try {
                yield 0;
            } catch (DisposedException) {
                yield 1;
            }
        })->getIterator();

        $generator->dispose();

        $this->expectException(DisposedException::class);

        $generator->continue();
    }

    public function testGetReturnAfterDisposal(): void
    {
        $generator = fromIterable(function () {
            try {
                yield 0;
            } catch (DisposedException) {
                return 1;
            }

            return 0;
        })->getIterator();

        $generator->dispose();

        $this->expectException(DisposedException::class);

        $generator->continue();
    }


    public function testGeneratorStartsOnlyAfterCallingContinue(): void
    {
        $invoked = false;
        $generator = fromIterable(function () use (&$invoked) {
            $invoked = true;
            yield 0;
        })->getIterator();

        self::assertFalse($invoked);

        self::assertTrue($generator->continue());
        self::assertSame(0, $generator->get());
        self::assertTrue($invoked);

        self::assertFalse($generator->continue());
    }

    public function testTraversable(): void
    {
        $values = [];

        $generator = fromIterable(function () {
            yield 1;
            yield 2;
            yield 3;
        });

        foreach ($generator as $value) {
            $values[] = $value;
        }

        self::assertSame([1, 2, 3], $values);
    }
}
