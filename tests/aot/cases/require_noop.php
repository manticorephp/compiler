<?php
// Whole-program AOT: the runtime LOAD is a no-op — declarations come from the
// compilation, not from reading a file at run time. The VALUE is not a no-op,
// and this case pins the arm that has no file behind it.
//
// require/include answers one of three things, decided by the compiled file set:
//   * a compile unit that ended in `return <expr>`  -> that value
//   * a compile unit that returned nothing          -> int(1), as php does
//   * a path that is not a compile unit at all      -> false  (this case)
//
// false is also what php's `include` of a missing file evaluates to. php
// additionally warns, and `require` fatals; neither happens here, because a
// compiled program's declarations are present either way and there is nothing
// to abort over. That is the manticore-only part — the VALUE agrees with php.
function localHelper(): int { return 42; }
require __DIR__ . '/__manticore_require_noop_absent__.php';
$v = include '__manticore_include_noop_absent__.php';
var_dump($v);
echo localHelper(), "\n";
echo "done\n";
