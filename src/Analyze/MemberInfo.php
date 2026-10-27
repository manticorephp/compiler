<?php

namespace Analyze;

/**
 * A declared class member. $kind is method | prop | const | case. $typeHint is the return type of a
 * method, the declared type of a property or constant, and the enum itself for a case.
 * $line / $col point at the member's name when the file was parsed with declaration names, else at the
 * start of the declaration.
 */
final class MemberInfo
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $declClass,
        public string $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public ?string $typeHint,
        public string $file,
        public int $line,
        public int $col,
    ) {}
}
