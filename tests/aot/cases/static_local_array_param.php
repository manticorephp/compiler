<?php
// A bare `array` parameter stored into a static local must be co-owned: the
// caller frees its temporary after the call, and the static used to keep it.
/** @param string[]|null $set @return string[] */
function st(?array $set = null): array
{
    static $order = ["A", "B"];
    if ($set !== null) { $order = $set; }
    return $order;
}
/** @return string[] */
function mk(string $s): array { $out = []; foreach (explode(",", $s) as $p) { $out[] = trim($p); } return $out; }
var_dump(st());
st(mk("x, y, z"));
var_dump(st());
st(["p", "q"]);
var_dump(st());
