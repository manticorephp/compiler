<?php

namespace Compile\Mir;

/**
 * One method, property or class constant as the class-declaration checks see it:
 * just the facts PHP's inheritance rules read. `declClass` is the class PHP names
 * in its messages — the declaring class, or the USING class for a trait import.
 */
final class DeclMember
{
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public bool $isFinal,
        public string $declClass,
        public bool $fromTrait = false,
        public ?\Parser\Ast\MethodDecl $decl = null,
        public ?string $type = null,
        public bool $isReadonly = false,
    ) {}
}
