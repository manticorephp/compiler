<?php

/**
 * CLI prelude — injected (compiled WITH the user program) when the source
 * references $argv / $argc / getopt(. See Main.php gating and
 * LowerFromAst::$cliSrc / injectCliSuperglobals.
 *
 * Lives here (not the stdlib .o) so the bare-`array` returns narrow at the
 * call site: __mc_argv builds a string[] from the captured process argv, and
 * getopt's mixed-value result rides the in-module cell-array path.
 *
 * `__mir_argc` / `__mir_argv_at` are codegen builtins (EmitLlvmBuiltins) over
 * the preamble's captured argc/argv; `cstr_to_str` copies a raw libc C-string
 * into an owned, headered MIR string.
 */

/**
 * The process argv as a real PHP array of strings (argv[0] is the program).
 * @return string[]
 */
function __mc_argv(): array
{
    $n = __mir_argc();
    $out = [];
    $i = 0;
    while ($i < $n) {
        $out[] = \cstr_to_str(__mir_argv_at($i));
        $i = $i + 1;
    }
    return $out;
}

/**
 * The process environment as a PHP array (`$_ENV`). `environ` hands us
 * "KEY=VALUE" strings; split on the FIRST '=' only — a value may contain more
 * (e.g. LS_COLORS). An entry with no '=' at all is not a variable; skip it.
 * @return array<string, string>
 */
function __mc_env(): array
{
    $n = __mir_env_count();
    $out = [];
    $i = 0;
    while ($i < $n) {
        $s = \cstr_to_str(__mir_env_at($i));
        $i = $i + 1;
        $eq = \strpos($s, '=');
        if ($eq === false) { continue; }
        $out[\substr($s, 0, $eq)] = \substr($s, $eq + 1);
    }
    return $out;
}

/**
 * `$_SERVER` for the CLI SAPI: the environment first, then the CLI keys PHP
 * adds on top of it (php.net/reserved.variables.server). Values are mixed —
 * argv is an ARRAY, argc an int — so the nested array is boxed to a cell, the
 * same way getopt's repeated option is.
 *
 * REQUEST_TIME / REQUEST_TIME_FLOAT are NOT here: the compiler has no time()
 * builtin yet. A program reading them gets null instead of a wrong number.
 * @return array<string, mixed>
 */
function __mc_server(): array
{
    // Built as a MIXED-valued array from the start: seeding it with __mc_env()'s
    // array<string,string> would pin the value type to `string`, and the int
    // argc / array argv stored below would erase to garbage.
    /** @var array<string, mixed> $out */
    $out = [];
    foreach (__mc_env() as $ek => $ev) { $out[$ek] = $ev; }
    $argv = __mc_argv();
    $self = $argv[0] ?? '';
    $out['PHP_SELF'] = $self;
    $out['SCRIPT_NAME'] = $self;
    $out['SCRIPT_FILENAME'] = $self;
    $out['PATH_TRANSLATED'] = $self;
    $out['DOCUMENT_ROOT'] = '';
    $out['argv'] = __mir_to_cell($argv);
    $out['argc'] = __mir_argc();
    return $out;
}

/**
 * Append a parsed option to the result, folding repeats into an array the way
 * PHP getopt does: first hit stores the scalar, the next promotes to a list.
 * @param array<string, mixed> $result
 */
function __mc_getopt_put(array &$result, string $name, mixed $value): void
{
    if (isset($result[$name])) {
        if (\is_array($result[$name])) {
            $result[$name][] = $value;
        } else {
            // Promote a repeated option to a list. The nested array must be
            // boxed to an ARRAY cell (__mir_to_cell) so the erased `array`
            // container keeps the tag — a raw store would read back as a scalar.
            $result[$name] = __mir_to_cell([$result[$name], $value]);
        }
    } else {
        $result[$name] = $value;
    }
}

/**
 * Parse a short-option spec ("ab:c::") into name => mode (0 none, 1 required,
 * 2 optional).
 * @return array<string, int>
 */
function __mc_getopt_short(string $spec): array
{
    $map = [];
    $len = \strlen($spec);
    $i = 0;
    while ($i < $len) {
        $c = \substr($spec, $i, 1);
        $i = $i + 1;
        if ($c === ':' || $c === '-' || $c === ' ') { continue; }
        $mode = 0;
        if ($i < $len && \substr($spec, $i, 1) === ':') {
            $mode = 1;
            $i = $i + 1;
            if ($i < $len && \substr($spec, $i, 1) === ':') {
                $mode = 2;
                $i = $i + 1;
            }
        }
        $map[$c] = $mode;
    }
    return $map;
}

/**
 * Parse a long-option spec (["foo", "bar:", "baz::"]) into name => mode.
 * @param string[] $opts
 * @return array<string, int>
 */
function __mc_getopt_long(array $opts): array
{
    $map = [];
    foreach ($opts as $opt) {
        $mode = 0;
        if (\str_ends_with($opt, '::')) {
            $mode = 2;
            $opt = \substr($opt, 0, \strlen($opt) - 2);
        } elseif (\str_ends_with($opt, ':')) {
            $mode = 1;
            $opt = \substr($opt, 0, \strlen($opt) - 1);
        }
        $map[$opt] = $mode;
    }
    return $map;
}

/**
 * getopt() — parse $argv for options. Supports short clustering (-abc),
 * inline (-ovalue / --opt=value) and separate (-o value / --opt value)
 * arguments, ':' required / '::' optional markers, repeats (→ array), and the
 * `--` end-of-options marker. `$rest_index` (by ref) receives the index of the
 * first non-option argument.
 *
 * @param string[] $long_options
 * @return array<string, mixed>
 */
function getopt(string $short_options, array $long_options = [], &$rest_index = null): array
{
    $argv = __mc_argv();
    $argc = \count($argv);
    $shortMap = __mc_getopt_short($short_options);
    $longMap = __mc_getopt_long($long_options);

    $result = [];
    $idx = 1;
    while ($idx < $argc) {
        $arg = $argv[$idx];
        $alen = \strlen($arg);
        if ($alen < 2 || \substr($arg, 0, 1) !== '-') {
            break;
        }
        if ($arg === '--') {
            $idx = $idx + 1;
            break;
        }

        if (\substr($arg, 1, 1) === '-') {
            // long option: --name or --name=value. $val stays string-typed
            // ($haveVal tracks presence) so its store into the mixed result
            // boxes as a string — a null sentinel widens it to a cell whose
            // string store loses its tag (renders as a raw pointer).
            $body = \substr($arg, 2);
            $name = $body;
            $val = "";
            $haveVal = false;
            $eq = \strpos($body, '=');
            if ($eq !== false) {
                $name = \substr($body, 0, $eq);
                $val = \substr($body, $eq + 1);
                $haveVal = true;
            }
            $idx = $idx + 1;
            if (!isset($longMap[$name])) {
                continue;
            }
            $mode = $longMap[$name];
            if ($mode === 1 && !$haveVal && $idx < $argc) {
                $val = \substr($argv[$idx], 0); // owned copy: a bare alias dangles when $argv is freed at return
                $haveVal = true;
                $idx = $idx + 1;
            }
            if ($mode !== 0 && $haveVal) {
                __mc_getopt_put($result, $name, $val);
            } else {
                __mc_getopt_put($result, $name, false);
            }
            continue;
        }

        // short cluster: -abc, -ovalue, -o value
        $j = 1;
        while ($j < $alen) {
            $c = \substr($arg, $j, 1);
            $j = $j + 1;
            if (!isset($shortMap[$c])) {
                continue;
            }
            $mode = $shortMap[$c];
            if ($mode === 0) {
                __mc_getopt_put($result, $c, false);
                continue;
            }
            $val = "";
            $haveVal = false;
            if ($j < $alen) {
                $val = \substr($arg, $j);
                $j = $alen;
                $haveVal = true;
            }
            if (!$haveVal && $mode === 1 && $idx + 1 < $argc) {
                $idx = $idx + 1;
                $val = \substr($argv[$idx], 0); // owned copy: a bare alias dangles when $argv is freed at return
                $haveVal = true;
            }
            if ($haveVal) {
                __mc_getopt_put($result, $c, $val);
            } else {
                __mc_getopt_put($result, $c, false);
            }
        }
        $idx = $idx + 1;
    }

    $rest_index = $idx;
    return $result;
}
