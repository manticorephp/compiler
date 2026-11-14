<?php

namespace Parser;

use Parser\Ast\ConstDecl;
use Parser\Ast\ErrorMember;
use Parser\Ast\EnumCase;
use Parser\Ast\MethodDecl;
use Parser\Ast\PropertyDecl;
use Parser\Ast\TraitAdaptation;

/**
 * Members collected while a class body is parsed. The member loop and the
 * per-member parse live in different methods (the tolerant loop wraps each
 * member in its own recovery); this carries the lists between them.
 */
final class ClassMembers
{
    /** @var PropertyDecl[] */
    public array $properties = [];
    /** @var MethodDecl[] */
    public array $methods = [];
    /** @var ConstDecl[] */
    public array $consts = [];
    /** @var EnumCase[] */
    public array $cases = [];
    /** @var string[] */
    public array $uses = [];
    /**
     * Trait name → the docblock on its `use` line (carries `@use Items<string>`,
     * the binding for a generic trait).
     * @var array<string,string>
     */
    public array $useDocs = [];
    /** @var TraitAdaptation[] */
    public array $traitAdaptations = [];
    /** @var ErrorMember[] */
    public array $errorMembers = [];
}
