<?php
// A Stringable object held as mixed passed to a string function does not call __toString
// issue: #63
final class S { public function __toString(): string { return 'ab'; } }
function t(mixed $v): void {
    echo str_repeat($v, 2), '|', str_pad($v, 4, '-'), '|', strtoupper($v), '|', ucfirst($v), '|', strlen($v), "\n";
    var_dump(in_array($v, ['ab']));
}
t(new S());
