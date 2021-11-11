<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use function Amp\coroutine;
use function Amp\delay;

class SharedSourceTest extends AsyncTestCase
{
    public function testBasicShare(): void
    {
        $expected = [1, 2, 3];

        $pipeline = Pipeline\fromIterable($expected);
        $source = Pipeline\share($pipeline);

        $pipeline1 = $source->asPipeline();
        $pipeline2 = $source->asPipeline();

        $future1 = coroutine(fn () => \iterator_to_array($pipeline1));
        $future2 = coroutine(fn () => \iterator_to_array($pipeline2));

        self::assertSame($expected, $future1->await());
        self::assertSame($expected, $future2->await());
    }

    public function testBackPressure(): void
    {
        $source = new Subject();
        $share = Pipeline\share($source->asPipeline());

        $pipeline1 = $share->asPipeline()->pipe(Pipeline\postpone(0.2));
        $pipeline2 = $share->asPipeline();

        $future1 = coroutine(fn () => $pipeline1->continue());
        $future2 = coroutine(fn () => $pipeline2->continue());

        $future3 = coroutine(fn () => $pipeline1->continue());
        $future4 = coroutine(fn () => $pipeline2->continue());

        $invoked = false;
        $source->emit(1)->finally(function () use (&$invoked): void {
            $invoked = true;
        })->ignore();

        delay(0.1); // Delayed pipeline *should not* have consumed the value yet.

        self::assertFalse($invoked);

        self::assertSame(1, $future1->await());
        self::assertSame(1, $future2->await());

        delay(0.2); // Ensure delayed pipeline has consumed value.

        self::assertTrue($invoked);

        $source->complete();

        self::assertNull($future3->await());
        self::assertNull($future4->await());
    }

    public function testDisposeShare(): void
    {
        $expected = [1, 2, 3];

        $pipeline = Pipeline\fromIterable($expected);
        $source = Pipeline\share($pipeline);

        $pipeline1 = $source->asPipeline();
        $pipeline2 = $source->asPipeline();
        $pipeline2->dispose();

        delay(0); // Tick event loop to trigger disposal callback.

        self::assertSame($expected, \iterator_to_array($pipeline1));
    }

    public function testShareAfterComplete(): void
    {
        $expected = [1, 2, 3];

        $pipeline = Pipeline\fromIterable($expected);

        self::assertSame($expected, \iterator_to_array($pipeline));

        $source = Pipeline\share($pipeline);

        self::assertSame([], \iterator_to_array($source->asPipeline()));
    }

    public function testShareAfterFail(): void
    {
        $exception = new TestException();

        $source = new Subject();
        $source->error($exception);
        $pipeline = $source->asPipeline();

        $this->expectExceptionObject($exception);
        \iterator_to_array(Pipeline\share($pipeline)->asPipeline());
    }

    public function testShareThenFail(): void
    {
        $exception = new TestException();

        $source = new Subject();

        $future = coroutine(fn () => \iterator_to_array(Pipeline\share($source->asPipeline())->asPipeline()));

        $source->emit(1);
        $source->error($exception);

        $this->expectExceptionObject($exception);
        $future->await();
    }

    public function testShareAfterDisposal(): void
    {
        $source = new Subject();

        $shared = Pipeline\share($source->asPipeline());
        $shared->asPipeline()->dispose();

        delay(0); // Tick event loop to trigger disposal callback.

        $this->expectException(DisposedException::class);
        \iterator_to_array($shared->asPipeline());
    }
}
