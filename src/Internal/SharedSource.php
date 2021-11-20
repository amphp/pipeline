<?php

namespace Amp\Pipeline\Internal;

use Amp\Future;
use Amp\Pipeline\Emitter;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Source;
use Revolt\EventLoop;

/**
 * @internal
 */
final class SharedSource implements Source
{
    /** @var Emitter[] */
    private array $sources = [];

    public function __construct(
        private Pipeline $pipeline,
    ) {
    }

    private function disperse(): void
    {
        $sources = &$this->sources;
        $pipeline = $this->pipeline;
        EventLoop::queue(static function () use (&$sources, $pipeline): void {
            try {
                foreach ($pipeline as $item) {
                    $futures = [];
                    foreach ($sources as $source) {
                        $futures[] = $source->emit($item);
                    }
                    Future\settle($futures); // A destination pipeline may be disposed.
                }

                foreach ($sources as $source) {
                    $source->complete();
                }
            } catch (\Throwable $exception) {
                foreach ($sources as $source) {
                    $source->error($exception);
                }
            } finally {
                $sources = [];
            }
        });
    }

    public function asPipeline(): Pipeline
    {
        $disperse = empty($this->sources);
        $this->sources[] = $source = new Emitter();

        $sources = &$this->sources;
        $pipeline = $this->pipeline;
        $source->onDisposal(static function () use (&$sources, $source, $pipeline): void {
            foreach ($sources as $index => $active) {
                if ($active === $source) {
                    unset($sources[$index]);
                    break;
                }
            }

            if (empty($sources)) {
                $pipeline->dispose();
            }
        });

        if ($disperse) {
            $this->disperse();
        }

        return $source->asPipeline();
    }
}
