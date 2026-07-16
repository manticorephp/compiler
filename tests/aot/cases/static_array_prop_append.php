<?php
// A static array property's `[]` default is not a link-time constant, so the
// global cell it lives in starts at 0 — an APPEND onto that NULL buffer used to
// SIGSEGV (a keyed store survived only because set_str allocates from null).
// The default must be built at __main entry, before any top-level statement.
final class Bag {
    public static array $xs = [];
    public static array $seeded = [7, 8];
}

var_dump(Bag::$seeded);
Bag::$seeded[] = 9;
echo count(Bag::$seeded), "\n";

// Appends from OUTSIDE the declaring class: the element type is only visible in
// these stores, so a read guessed a repr and implode printed `2.1e-314`.
Bag::$xs[] = "a";
Bag::$xs[] = "b";
echo count(Bag::$xs), "\n";
echo implode(",", Bag::$xs), "\n";
var_dump(Bag::$xs[0]);

// Mixed element types collapse to a tagged cell, each rendering by its own tag.
final class Mixed_ {
    public static array $vals = [];
}
Mixed_::$vals[] = "s";
Mixed_::$vals[] = 1;
Mixed_::$vals[] = 2.5;
Mixed_::$vals[] = true;
var_dump(Mixed_::$vals);

// PHP array value semantics: reading a static array into a local snapshots it —
// appending to the copy must not mutate the static.
final class Rows {
    public static array $r = [];
}
Rows::$r[] = 1;
$copy = Rows::$r;
$copy[] = 2;
echo count(Rows::$r), " ", count($copy), "\n";
