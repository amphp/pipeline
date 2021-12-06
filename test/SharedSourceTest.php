<?php

namespace Amp\Pipeline;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use function Amp\async;
use function Amp\delay;

class SharedSourceTest extends AsyncTestCase
{
    public function testBasicShare(): void
    {
        $expected = [1, 2, 3];

        $pipeline = Pipeline\fromIterable($expected);
        $source = Pipeline\share($pipeline);

        $pipeline1 = $source->pipe();
        $pipeline2 = $source->pipe();

        $future1 = async(fn () => \iterator_to_array($pipeline1));
        $future2 = async(fn () => \iterator_to_array($pipeline2));

        self::assertSame($expected, $future1->await());
        self::assertSame($expected, $future2->await());
    }

    public function testBackPressure(): void
    {
        $source = new Emitter();
        $share = Pipeline\share($source->pipe());

        $pipeline1 = $share->pipe()->pipe(Pipeline\postpone(0.2));
        $pipeline2 = $share->pipe();

        $future1 = async(fn () => $pipeline1->continue());
        $future2 = async(fn () => $pipeline2->continue());

        $future3 = async(fn () => $pipeline1->continue());
        $future4 = async(fn () => $pipeline2->continue());

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

        $pipeline1 = $source->pipe();
        $pipeline2 = $source->pipe();
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

        self::assertSame([], \iterator_to_array($source->pipe()));
    }

    public function testShareAfterFail(): void
    {
        $exception = new TestException();

        $source = new Emitter();
        $source->error($exception);
        $pipeline = $source->pipe();

        $this->expectExceptionObject($exception);
        \iterator_to_array(Pipeline\share($pipeline)->pipe());
    }

    public function testShareThenFail(): void
    {
        $exception = new TestException();

        $source = new Emitter();

        $future = async(fn () => \iterator_to_array(Pipeline\share($source->pipe())->pipe()));

        $source->emit(1);
        $source->error($exception);

        $this->expectExceptionObject($exception);
        $future->await();
    }

    public function testShareAfterDisposal(): void
    {
        $source = new Emitter();

        $shared = Pipeline\share($source->pipe());
        $shared->pipe()->dispose();

        delay(0); // Tick event loop to trigger disposal callback.

        $this->expectException(DisposedException::class);
        \iterator_to_array($shared->pipe());
    }
}
