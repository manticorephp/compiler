<?php

/**
 * PHP-shaped backtrace frames — injected only when the program QUERIES a trace
 * (`->getTrace()`, `->getTraceAsString()`, `debug_backtrace()`; see the demand
 * gate in Main.php). A trace user also makes EmitLlvm push a frame at every
 * call, so this is not a free feature; the programs that want none get
 * `backtrace_stub.php` instead.
 *
 * The captured name is the combined display ("Class->method" / "Class::m" /
 * "fn"); split it back into function/class/type so getTrace() and
 * debug_backtrace() match PHP's frame assoc. Frames carry file + line +
 * function[/class/type]; `args` is still omitted.
 *
 * `line` is a real int mixed with the substr-derived strings (a cell assoc) —
 * this used to miscompile; fixed by retaining string payloads boxed into a cell
 * array (see EmitLlvm::retainCellPayload).
 */

/**
 * @param string[] $names
 * @param int[] $lines
 * @param string[] $files the file of each frame's call site, '' where unrecorded
 * @return array<int,array<string,mixed>>
 */
function __mir_bt_frames(array $names, array $lines, string $file, array $files = [], int $limit = 0): array
{
    $out = [];
    $n = \count($names);
    if ($limit > 0 && $n > $limit) { $n = $limit; }
    $i = 0;
    $top = $file;
    while ($i < $n) {
        $name = $names[$i];
        $ln = $lines[$i];
        $file = ($files[$i] ?? '') !== '' ? (string)$files[$i] : $top;
        $internal = $file === "\0";
        $type = "";
        $p = \strpos($name, "::");
        if ($p === false) {
            $p = \strpos($name, "->");
            if ($p !== false) { $type = "->"; }
        } else {
            $type = "::";
        }
        $fr = $internal ? [] : ["file" => $file, "line" => $ln];
        if ($type !== "") {
            $fr["function"] = \substr($name, $p + 2);
            $fr["class"] = \substr($name, 0, $p);
            $fr["type"] = $type;
        } else {
            $fr["function"] = $name;
        }
        $out[] = $fr;
        $i = $i + 1;
    }
    return $out;
}

/**
 * The `object` of each method frame, from the words its entry recorded: valid while the name it was
 * recorded beside is still that frame's name (a later function at the same depth overwrote neither).
 * @param array<int,array<string,mixed>> $frames
 * @param int[] $names
 * @param int[] $objs
 * @param int[] $objn
 * @return array<int,array<string,mixed>>
 */
function __mir_bt_with_objects(array $frames, array $names, array $objs, array $objn, int $flags): array
{
    if (($flags & 1) === 0) { return $frames; }
    $n = \count($frames);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = $objs[$i] ?? 0;
        if ($o !== 0 && ($objn[$i] ?? 0) === ($names[$i] ?? -1)) {
            $frames[$i]['object'] = \__mc_obj_from_addr($o);
        }
    }
    return $frames;
}

/**
 * `debug_print_backtrace()` — `#N file(line): Class->fn()` per frame, outermost
 * last. Frames carry no argument values here (see the header), so the argument
 * list prints empty and `DEBUG_BACKTRACE_IGNORE_ARGS` changes nothing.
 */
function debug_print_backtrace(int $options = 0, int $limit = 0): void
{
    // Frame 0 is this function's own: php's builtin has none.
    $frames = \debug_backtrace();
    $n = \count($frames) - 1;
    if ($limit > 0 && $n > $limit) { $n = $limit; }
    $i = 0;
    while ($i < $n) {
        $f = $frames[$i + 1];
        $name = $f['function'];
        if (isset($f['class'])) { $name = $f['class'] . $f['type'] . $name; }
        echo '#', $i, ' ', $f['file'], '(', $f['line'], '): ', $name, "()\n";
        $i = $i + 1;
    }
}
