<?php
// `$b = $s` on an obj/string local RETAINS, so the source's own release cannot
// free it early — and nothing ever released that retain: every aliased string
// in a function leaked one reference per call. The two halves are one decision;
// a missing release leaks, an extra one frees the alias under its reader.

function head(string $x): string
{
    $s = $x;                 // the alias the retain is for
    $n = strlen($s);
    if ($n > 4) { $s = substr($s, 0, 4); }   // …and an overwrite of it
    return $s;
}

function survivesSourceRebind(string $x): string
{
    $s = $x;
    $b = $s;                 // an alias of an alias
    $s = "clobbered";        // the source is rebound here
    return $b . "/" . $s;    // $b must still be intact
}

function aliasThenUnset(string $x): string
{
    $s = $x;
    $b = $s;
    unset($s);
    return $b;
}

class Box
{
    public function __construct(public string $tag) {}
}

function objAlias(Box $b): string
{
    $o = $b;
    $c = $o;
    return $c->tag;
}

$out = [];
for ($i = 0; $i < 5; $i++) {
    $out[] = head("alpha,beta,gamma" . $i);
    $out[] = survivesSourceRebind("src" . $i);
    $out[] = aliasThenUnset("uns" . $i);
    $out[] = objAlias(new Box("tag" . $i));
}
foreach ($out as $o) { echo $o, "\n"; }

// The stdlib shape this was found in: str_getcsv opens with `$s = $string;`.
$row = str_getcsv("alpha,beta,gamma", ",", chr(34), chr(92));
echo count($row), " ", implode("|", $row), "\n";
