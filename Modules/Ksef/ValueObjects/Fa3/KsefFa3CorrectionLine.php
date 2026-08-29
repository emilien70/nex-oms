<?php

namespace Modules\Ksef\ValueObjects\Fa3;

final readonly class KsefFa3CorrectionLine
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public int $logicalPosition,
        public array $before,
        public array $after,
    ) {}
}
