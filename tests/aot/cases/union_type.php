<?php
function describe(int|string $x): string {
    if (is_int($x)) { return "int"; }
    if (is_string($x)) { return "string"; }
    return "other";
}
echo describe(42), " ", describe("hi");
