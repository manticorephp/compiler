<?php

namespace Analyze;

use Parser\Ast\Param;

/** A resolved method signature plus the metadata needed to check a call site. */
final class MethodInfo
{
    /** @param Param[] $params */
    public function __construct(
        public string $name,
        public array $params,
        public bool $isStatic,
        public string $visibility,
        public ?string $returnType,
        public string $declClass,
    ) {}
}
