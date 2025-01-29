<?php

namespace MartinPetricko\LaravelApprovals\Helpers;

use Jfcherng\Diff\DiffHelper;

class Diff
{
    public function __construct(
        public array $oldData,
        public array $newData,
        protected string $renderer,
        protected array $rendererOptions,
    ) {
        //
    }

    public static function make(array $oldData, array $newData): static
    {
        return new static($oldData, $newData, config('approvals.diff.renderer'), config('approvals.diff.renderer_options'));
    }

    public function calculateDiffs(?string $renderer = null, ?array $rendererOptions = null): array
    {
        $diffs = [];
        foreach (array_keys($this->newData) as $attribute) {
            $diffs[$attribute] = DiffHelper::calculate((string)($this->oldData[$attribute] ?? null), (string)($this->newData[$attribute] ?? null), renderer: $renderer ?: $this->renderer, rendererOptions: $rendererOptions ?: $this->rendererOptions);
        }
        return array_filter($diffs);
    }
}
