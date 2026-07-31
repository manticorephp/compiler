<?php
// @epic: sapi-layer
// @why: symfony/console's Application and its Tester set SHELL_VERBOSITY through
//       putenv() and read it back with getenv(). getenv() exists as a codegen
//       builtin reading the real environ; putenv() does not, so the value never
//       round-trips and verbosity handling silently takes the default path.

var_dump(getenv('CAP_PROBE_VAR'));
putenv('CAP_PROBE_VAR=hello');
var_dump(getenv('CAP_PROBE_VAR'));
putenv('CAP_PROBE_VAR=changed');
var_dump(getenv('CAP_PROBE_VAR'));
putenv('CAP_PROBE_VAR');
var_dump(getenv('CAP_PROBE_VAR'));
