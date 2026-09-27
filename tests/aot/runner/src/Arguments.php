<?php

declare(strict_types=1);

namespace Manticore\TestRunner;

use Manticore\Attr\Struct;

#[Struct]
class Arguments
{
    public function __construct(
        public string $filter = '',
        public string $opt = '2',
        public ?int $customWorkers = null,
    ) {
    }
}
