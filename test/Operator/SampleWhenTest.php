<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Emitter;
use Revolt\EventLoop;

class SampleWhenTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $this->setTimeout(3);

        $values = [1, 2, 3, 4, 5, 6];
        $expected = [2, 4, 6];

        $sample = Pipeline\fromIterable(\range(0, 10))->pipe(Pipeline\postpone(0.7));

        $pipeline = Pipeline\fromIterable($values)->pipe(
            Pipeline\postpone(0.3),
            Pipeline\sampleWhen($sample)
        );

        $count = 0;
        while (null !== $value = $pipeline->continue()) {
            ++$count;
            self::assertSame(\array_shift($expected), $value);
        }

        self::assertSame(3, $count);
    }

    public function testSampleTime(): void
    {
        $this->setTimeout(3);

        $values = [1, 2, 3, 4, 5, 6];
        $expected = [2, 4, 6];

        $pipeline = Pipeline\fromIterable($values)->pipe(
            Pipeline\postpone(0.3),
            Pipeline\sampleTime(0.7)
        );

        $count = 0;
        while (null !== $value = $pipeline->continue()) {
            ++$count;
            self::assertSame(\array_shift($expected), $value);
        }

        self::assertSame(3, $count);
    }


    public function testSourcePipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $sample = Pipeline\merge([
            Pipeline\fromIterable([1]),
            Pipeline\fromIterable([2, 3])->pipe(Pipeline\postpone(0.1))
        ]);

        $pipeline = $source->pipe()->pipe(Pipeline\sampleWhen($sample));

        $source->emit(1)->ignore();
        EventLoop::queue(fn () => $source->error($exception));

        self::assertSame(1, $pipeline->continue());

        $this->expectExceptionObject($exception);
        $pipeline->continue();
    }

    public function testSamplePipelineFails(): void
    {
        $exception = new TestException;
        $source = new Emitter;

        $delay = Pipeline\merge([
            Pipeline\fromIterable([1]), // Emit the first value immediately.
            Pipeline\fromIterable([2])->pipe(Pipeline\postpone(0.1)) // Postpone subsequent value.
        ]);

        $pipeline = Pipeline\fromIterable([1, 2, 3])->pipe(
            Pipeline\postponeUntil($delay),
            Pipeline\sampleWhen($source->pipe())
        );

        $source->emit(1)->ignore();
        EventLoop::queue(fn () => $source->error($exception));

        self::assertSame(1, $pipeline->continue());

        $this->expectExceptionObject($exception);
        $pipeline->continue();
    }
}
