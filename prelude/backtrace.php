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
 * @return array<int,array<string,mixed>>
 */
function __mir_bt_frames(array $names, array $lines, string $file): array
{
    $out = [];
    $n = \count($names);
    $i = 0;
    while ($i < $n) {
        $name = $names[$i];
        $ln = $lines[$i];
        $type = "";
        $p = \strpos($name, "::");
        if ($p === false) {
            $p = \strpos($name, "->");
            if ($p !== false) { $type = "->"; }
        } else {
            $type = "::";
        }
        if ($type !== "") {
            $cls = \substr($name, 0, $p);
            $fn = \substr($name, $p + 2);
            $out[] = ["file" => $file, "line" => $ln, "function" => $fn, "class" => $cls, "type" => $type];
        } else {
            $out[] = ["file" => $file, "line" => $ln, "function" => $name];
        }
        $i = $i + 1;
    }
    return $out;
}
