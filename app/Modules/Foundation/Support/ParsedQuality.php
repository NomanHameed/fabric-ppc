<?php

namespace App\Modules\Foundation\Support;

final readonly class ParsedQuality
{
    public function __construct(
        public ?int $ends,
        public ?int $picks,
        public ?string $warpCount,
        public ?string $weftCount,
        public ?string $composition,
        public ?string $loom,
        public ?string $notes,
    ) {}
}
