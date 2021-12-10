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

        $generator = fromIterable(static fn () => null);

        $generator->continue();
    }

    public function testThrowingClosure(): void
    {
        $exception = new \Exception;

        $this->expectExceptionObject($exception);

        $generator = fromIterable(static fn () => throw $exception);

        $generator->continue();
    }

    public function testYield(): void
    {
        $value = 1;

        $generator = fromIterable(function () use ($value) {
            yield $value;
        });

        self::assertSame($value, $generator->continue());
        self::assertNull($generator->continue());
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
            $generator->continue();
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

        while (null !== $yielded = $generator->continue()) {
            $output .= $yielded;
            delay(self::TIMEOUT);
        }

        $expected = \implode('', \range(0, $yields - 1));

        self::assertSame($expected, $output);
        self::assertGreaterThan(self::TIMEOUT * ($yields - 1), $time * 1000);
    }

    /**
     * @depends testYield
     */
    public function testAsyncGeneratorThrows(): void
    {
        $exception = new TestException;

        try {
            $generator = fromIterable(function () use ($exception) {
                yield 1;
                throw $exception;
            });

            $generator->continue();
            $generator->continue();

            self::fail("The exception thrown from the generator should fail the pipeline");
        } catch (TestException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function testDisposal(): void
    {
        $generator = fromIterable(function () {
            yield 0;
            yield 1;
        });

        self::assertSame(0, $generator->continue());

        $generator->dispose();

        delay(0); // Tick event loop to destroy generator.

        try {
            $generator->continue();
            $generator->continue();
            self::fail("Pipeline should have been disposed");
        } catch (DisposedException $exception) {
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
        });

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
        });

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
        });

        self::assertFalse($invoked);

        self::assertSame(0, $generator->continue());
        self::assertTrue($invoked);

        self::assertNull($generator->continue());
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
