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
     * @param array<string, MemberInfo> $members  `kind:name` → member (methods keyed by lowercased name)
     * @param bool $partial  recovered from a file with syntax errors: members may be missing
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
        public bool $partial = false,
        public array $members = [],
    ) {}
}
