<?php

namespace Analyze;

use Parser\Ast\Param;

/** A resolved free function's signature, keyed in the {@see Index} by FQN. */
final class FnInfo
{
    /** @param Param[] $params */
    public function __construct(
        public string $name,
        public array $params,
        public ?string $returnType,
    ) {}
}
