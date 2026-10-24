<?php

namespace Compile\Mir;

/**
 * Members a class exposes after inheritance, in Zend's function-table order:
 * own, trait-imported, parent's, interfaces'. Keyed by lower-cased method name /
 * exact property or constant name. `complete` is false when an ancestor has no
 * declaration here (a library class, a cycle), so "abstract method left
 * unimplemented" cannot be decided.
 */
final class DeclTable
{
    /** @var array<string, DeclMember> */
    public array $methods = [];

    /** @var array<string, DeclMember> */
    public array $props = [];

    /** @var array<string, DeclMember> */
    public array $consts = [];

    public bool $complete = true;
}
