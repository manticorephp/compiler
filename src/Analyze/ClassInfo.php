<?php

namespace Analyze;

/**
 * A resolved class / interface / trait / enum. Names in `$parents`,
 * `$interfaces` and `$traits` are already FQN-resolved (the parser applies
 * use-aliases + the current namespace via `resolveClassName`), so they key
 * straight back into {@see Index::$classes} after lowercasing.
 */
final class ClassInfo
{
    /**
     * @param string[]                  $parents     `extends` targets (FQN)
     * @param string[]                  $interfaces  `implements` targets (FQN)
     * @param string[]                  $traits      `use` trait targets (FQN)
     * @param array<string, MethodInfo> $methods     lower method name → info
     * @param array<string, bool>       $consts      const / enum-case name → true
     * @param array<string, ?string>    $propTypes   property name → declared hint
     *                                                (incl. promoted ctor params)
     */
    public function __construct(
        public string $fqn,
        public string $kind,
        public array $parents,
        public array $interfaces,
        public array $traits,
        public array $methods,
        public array $consts,
        public bool $isAbstract,
        public array $propTypes = [],
    ) {}
}
