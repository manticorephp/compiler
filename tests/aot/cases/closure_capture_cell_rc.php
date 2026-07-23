<?php
// A closure capturing a CELL/UNKNOWN-typed rc value (an object read off a bare
// array) must RETAIN it — else the value is freed when the enclosing scope drops
// its reference, and the closure dangles (use-after-free). Regression for the
// closure-cell-capture rc bug (found by the async spike: a fiber captured a
// socket that the accept loop had already freed).
class Res {
    public function __construct(public string $name) {}
}
$holders = [];
for ($i = 0; $i < 3; $i++) {
    $tmp = [new Res("r" . $i)];   // bare array → element read is cell/unknown
    $obj = $tmp[0];
    $holders[] = function () use ($obj) {
        return $obj->name;
    };
    // $tmp and $obj are reassigned/dropped on the next iteration
}
$out = "";
foreach ($holders as $h) {
    $out .= $h() . " ";
}
echo $out, "\n";   // expect: r0 r1 r2
