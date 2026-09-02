<?php

/**
 * `$o->$name` on an ERASED receiver against several classes declaring the same
 * and different names, plus a real dynamic bag. The dispatch is extracted into
 * one shared reader per property, so this checks that the extraction still
 * picks the right holder's slot, the right bag entry, and the null default.
 *
 * Only names that EXIST on the receiver are read: an undefined dynamic property
 * is a separate, pre-existing crash (the erased bag default reads a bag pointer
 * out of a non-bag object), tracked on its own.
 */

class Alpha
{
    public int $id = 1;
    public string $label = 'alpha';
}

class Beta
{
    public int $id = 2;
    public int $count = 20;
}

class Gamma
{
    public string $label = 'gamma';
}

function readDyn(mixed $o, string $f): mixed
{
    return $o->$f;
}

foreach ([['a', new Alpha(), ['id', 'label']],
          ['b', new Beta(), ['id', 'count']],
          ['g', new Gamma(), ['label']]] as $row) {
    foreach ($row[2] as $f) {
        echo $row[0], '->', $f, ' = ', readDyn($row[1], $f), "\n";
    }
}

$bag = new stdClass();
$bag->id = 99;
$bag->label = 'bagged';
echo 'std->id = ', readDyn($bag, 'id'), "\n";
echo 'std->label = ', readDyn($bag, 'label'), "\n";
