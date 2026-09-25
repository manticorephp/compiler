<?php
// A cell lvalue (`?int &$count`) handed on to a builtin's SCALAR by-ref param
// (`preg_replace(…, int &$count)`) reads back the int the callee wrote —
// php-cs-fixer's `while (0 !== $count)` loop never ended.
final class P {
    public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1, ?int &$count = null): string
    {
        $result = @preg_replace($pattern . 'u', $replacement, $subject, $limit, $count);
        if (null !== $result && \PREG_NO_ERROR === preg_last_error()) { return $result; }
        return $subject;
    }
}
$c = "/**\n\t * a\n  \t * b\n */";
$r = preg_replace('/^(\ +)?\t/m', '\1    ', $c, -1, $n);
var_dump(json_encode($r), $n);
$content = P::replace('/^(?:(?<! ) {1,3})?\t/m', '\1    ', $c, -1, $count);
var_dump(json_encode($content), $count);
$i = 0;
while (0 !== $count && $i++ < 5) { $content = P::replace('/^(\ +)?\t/m', '\1    ', $content, -1, $count); var_dump($count); }
function fill(?float &$f, ?bool &$b): void { $f = 2.5; $b = true; }
function relay(?float &$f, ?bool &$b): void { fill($f, $b); }
$f = null; $b = null; relay($f, $b); var_dump($f, $b);
