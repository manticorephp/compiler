<?php

function fill(array &$v): void { $v[] = 1; $v[] = 2; }

function inc(int &$n): void { $n = $n + 5; }

function addKey(array &$a): void { $a['y'] = 2; }

function viaStatic(): int
{
    static $v = [];
    fill($v);
    return count($v);
}

function viaStaticScalar(): int
{
    static $n = 0;
    inc($n);
    return $n;
}

$store = ['x' => 1];

function viaGlobal(): void
{
    global $store;
    addKey($store);
    var_dump($store['x'], $store['y'], count($store));
}

$bag = [];

function viaGlobalAppend(): int
{
    global $bag;
    fill($bag);
    return count($bag);
}

var_dump(viaStatic());
var_dump(viaStatic());
var_dump(viaStaticScalar());
var_dump(viaStaticScalar());
viaGlobal();
var_dump(viaGlobalAppend());
var_dump(viaGlobalAppend());
var_dump($bag[0], $bag[3]);
