<?php
// A namespaced function named like a codegen builtin must not capture global or cross-namespace calls.
namespace Foo\Bar {
    function getenv(?string $name = null, bool $local_only = false): string|array|false
    {
        return 'shadowed';
    }
    function strlen(string $s): int { return 99; }
    function in_ns() { return \getenv('MC_NS_UNSET_VAR_X'); }
    function in_ns_bare() { return getenv('MC_NS_UNSET_VAR_X') . strlen('abc'); }
}
namespace Other {
    function bare() { return getenv('MC_NS_UNSET_VAR_X'); }
    function bare_len() { return strlen('abcd'); }
}
namespace {
    var_dump(getenv('MC_NS_UNSET_VAR_X'));
    var_dump(strlen('abc'));
    var_dump(\Other\bare());
    var_dump(\Other\bare_len());
    var_dump(\Foo\Bar\in_ns());
    var_dump(\Foo\Bar\in_ns_bare());
}
