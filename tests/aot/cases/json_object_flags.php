<?php
// json_encode of an OBJECT with any non-zero flag leaves the native encoder for
// the compiled-PHP walker, whose `(array)$v` runs inside manticore_stdlib.o —
// a module with no user class table. Every object answered `{}` until the
// walker learned to reach the class's own `@__mir_props_<id>` through its
// descriptor.
class Point
{
    public int $x = 3;
    public string $label = "origin";
}

$p = new Point();
echo json_encode($p, JSON_PRETTY_PRINT), "\n";
echo json_encode([$p], JSON_PRETTY_PRINT), "\n";
echo json_encode(["k" => $p], JSON_UNESCAPED_SLASHES), "\n";

$s = new stdClass();
$s->a = 1;
$s->b = "two";
echo json_encode($s, JSON_PRETTY_PRINT), "\n";
