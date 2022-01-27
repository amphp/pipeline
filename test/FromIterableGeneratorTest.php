<?php

namespace Amp\Pipeline;

use Amp\DeferredFuture;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;
use function Amp\now;

class FromIterableGeneratorTest extends AsyncTestCase
{
    private const TIMEOUT = 0.1;

    public function testNonGeneratorClosure(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Return value of argument #1 ($iterable) must be of type iterable, null returned');

        Pipeline::fromIterable(static fn () => null);
    }

    public function testThrowingClosure(): void
    {
        $exception = new \Exception;

        $this->expectExceptionObject($exception);

        Pipeline::fromIterable(static fn () => throw $exception);
    }

    public function testYield(): void
    {
        $value = 1;

        $generator = Pipeline::fromIterable(function () use ($value) {
            yield $value;
        });

        self::assertSame([1], $generator->toArray());
    }

    public function testLazy(): void
    {
        $generator = Pipeline::fromIterable(static function () {
            print '1';
            yield;
            print '2';
            yield;
            print '3';
        });

        // ensure async is already executed
        delay(0);

        print 'a';

        $iterator = $generator->getIterator();

        print 'b';

        $iterator->continue();

        print 'c';

        $iterator->continue();

        print 'd';

        $this->expectOutputString('ab1c2d');
    }

    public function testLazyConcurrent(): void
    {
        $generator = Pipeline::fromIterable(static function () {
            print '1';
            yield;
            print '2';
            delay(1);
            print '3';
            yield;
            print '4';
            yield;
            print '5';
        });

        // ensure async is already executed
        delay(0);

        print 'a';
        $iterator = $generator->getIterator();

        print 'b';
        $iterator->continue();

        print 'c';
        $futures[] = async(fn () => $iterator->continue());
        $futures[] = async(fn () => $iterator->continue());

        print 'd';
        awaitAll($futures);

        print 'e';

        $this->expectOutputString('ab1cd234e');
    }

    public function testLazyConcurrentTap(): void
    {
        $generator = Pipeline::fromIterable(static function () {
            print '1';
            yield;
            print '2';
            delay(1);
            print '3';
            yield;
            print '4';
            yield;
            print '5';
        });

        // ensure async is already executed
        delay(0);

        print 'a';
        $generator->concurrent(2)->tap(fn () => null)->getIterator(); // lazy and does nothing
        print 'b';
        delay(1);
        print 'c';

        $this->expectOutputString('abc');
    }

    /**
     * @depends testYield
     */
    public function testFailingPromise(): void
    {
        $exception = new TestException;
        $deferred = new DeferredFuture;

        $generator = Pipeline::fromIterable(function () use ($deferred) {
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

        $generator = Pipeline::fromIterable(function () use (&$time, $yields) {
            $time = now();
            for ($i = 0; $i < $yields; ++$i) {
                yield $i;
            }
            $time = now() - $time;
        });

        $iterator = $generator->getIterator();
        while ($iterator->continue()) {
            $output .= $iterator->getValue();
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
            $generator = Pipeline::fromIterable(function () use ($exception) {
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
        $iterator = Pipeline::fromIterable(function () use (&$invoked) {
            try {
                yield 0;
                yield 1;
            } finally {
                $invoked = true;
            }
        })->getIterator();

        self::assertTrue($iterator->continue());
        self::assertSame(0, $iterator->getValue());

        self::assertFalse($invoked);

        $iterator->dispose();

        delay(0); // Tick event loop to destroy generator.

        try {
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
        $generator = Pipeline::fromIterable(function () {
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
        $generator = Pipeline::fromIterable(function () {
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
        $generator = Pipeline::fromIterable(function () use (&$invoked) {
            $invoked = true;
            yield 0;
        })->getIterator();

        self::assertFalse($invoked);

        self::assertTrue($generator->continue());
        self::assertSame(0, $generator->getValue());
        self::assertTrue($invoked);

        self::assertFalse($generator->continue());
    }

    public function testTraversable(): void
    {
        $values = [];

        $generator = Pipeline::fromIterable(function () {
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
