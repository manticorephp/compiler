<?php

namespace Analyze;

use Parser\Ast\Param;

/** A resolved free function's signature, keyed in the {@see Index} by FQN. */
final class FnInfo
{
    /**
     * @param Param[] $params
     * @param array<string, string> $paramDocs param name → qualified docblock type
     */
    public function __construct(
        public string $name,
        public array $params,
        public ?string $returnType,
        public ?string $docType = null,
        public array $paramDocs = [],
        public ?TemplateSig $tpl = null,
        /** A stdlib function: php's internal-function rules apply at its call sites (surplus arguments, null and Stringable coerced). */
        public bool $internal = false,
    ) {}
}
