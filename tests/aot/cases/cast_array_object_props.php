<?php
// `(array)$obj` is the DECLARED properties plus whatever the dynamic bag holds.
// Over an ERASED receiver the cast read the bag ALONE, dispatching on class_id
// with an arm only for classes that HAVE a bag. A class with declared
// properties and no bag therefore fell to the default and loaded its FIRST
// DECLARED SLOT at stdClass's bag offset as an assoc pointer — `public int $id`
// became a pointer and was retained. Where it did not fault it answered `[]`,
// which is the `{}` that json_encode of an object renders.

class P {
    public int $id = 3;
    public string $n = "z";
}

#[AllowDynamicProperties]
class Q {
    public int $a = 1;
}

function props(mixed $v): string {
    $out = "";
    foreach ((array)$v as $k => $val) {
        $ks = is_string($k) ? $k : (string)$k;
        $out = $out . "[" . $ks . "=" . (is_string($val) ? $val : (string)$val) . "]";
    }
    return $out;
}

$q = new Q();
$q->extra = 9;

// Erased receiver: read back through a mixed-valued array so inference cannot
// answer what only the runtime class knows.
$box = ["plain" => new P(), "bag" => $q, "std" => (object)["x" => 5, "y" => "w"]];
echo props($box["plain"]), "\n";
echo props($box["bag"]), "\n";
echo props($box["std"]), "\n";
