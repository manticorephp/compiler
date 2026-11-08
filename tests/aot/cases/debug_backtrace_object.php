<?php
// debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) names each method frame's `$this`: Twig UX looks for the Template there.
class Tpl { public function __construct(public string $n) {} public function render(Inner $i) { return $i->id(); } }
class Inner { public function id() { return helper($this); } public static function st() { return self::look(); } private static function look() { return objs(); } }
function objs(int $f = DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT): array {
    $out = [];
    foreach (debug_backtrace($f) as $t) { $out[] = ($t['function'] ?? '?') . ':' . (isset($t['object']) ? get_class($t['object']) . (isset($t['object']->n) ? '/' . $t['object']->n : '') : '-'); }
    return $out;
}
function helper(object $o) { return objs(); }
echo implode(' ', (new Tpl('a'))->render(new Inner)), "\n";
echo implode(' ', Inner::st()), "\n";
echo implode(' ', objs(DEBUG_BACKTRACE_IGNORE_ARGS)), "\n";
foreach (debug_backtrace() as $x) { echo isset($x['object']) ? 'obj' : 'none'; } echo count(debug_backtrace()), "\n";
