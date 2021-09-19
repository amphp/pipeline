<?php

namespace Amp\Pipeline\Operator;

use Amp\PHPUnit\AsyncTestCase;
use Amp\PHPUnit\TestException;
use Amp\Pipeline;
use Amp\Pipeline\Subject;
use function Revolt\EventLoop\queue;

class SampleWhenTest extends AsyncTestCase
{
    public function testValuesEmitted(): void
    {
        $this->setTimeout(1);

        $values = [1, 2, 3, 4, 5, 6, 7];
        $expected = [2, 4, 6];

        $sample = Pipeline\fromIterable(\range(0, 10))->pipe(Pipeline\delay(0.21));

        $pipeline = Pipeline\fromIterable($values)->pipe(
            Pipeline\delay(0.1),
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
        $this->setTimeout(1);

        $values = [1, 2, 3, 4, 5, 6, 7];
        $expected = [2, 4, 6];

        $pipeline = Pipeline\fromIterable($values)->pipe(
            Pipeline\delay(0.1),
            Pipeline\sampleTime(0.21)
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
        $source = new Subject;

        $sample = Pipeline\merge([
            Pipeline\fromIterable([1]),
            Pipeline\fromIterable([2, 3])->pipe(Pipeline\delay(0.1))
        ]);

        $pipeline = $source->asPipeline()->pipe(Pipeline\sampleWhen($sample));

        $source->emit(1)->ignore();
        queue(fn() => $source->error($exception));

        self::assertSame(1, $pipeline->continue());

        $this->expectExceptionObject($exception);
        $pipeline->continue();
    }

    public function testSamplePipelineFails(): void
    {
        $exception = new TestException;
        $source = new Subject;

        $delay = Pipeline\merge([
            Pipeline\fromIterable([1]),
            Pipeline\fromIterable([2, 3])->pipe(Pipeline\delay(0.1))
        ]);

        $pipeline = Pipeline\fromIterable([1, 2, 3])->pipe(
            Pipeline\delayWhen($delay),
            Pipeline\sampleWhen($source->asPipeline())
        );

        $source->emit(1)->ignore();
        queue(fn() => $source->error($exception));

        self::assertSame(1, $pipeline->continue());

        $this->expectExceptionObject($exception);
        $pipeline->continue();
    }
}
