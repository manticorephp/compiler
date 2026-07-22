<?php

interface HasVersion { const VERSION = '1.0'; }

class Config implements HasVersion
{
    const MAX = 100;
    const MIN = 0;
    const NAME = 'cfg';
    protected const SCALE = 2.5;
    const ITEMS = ['a', 'b'];
    const DERIVED = self::MAX * 2;
}

class Sub extends Config
{
    const EXTRA = 'x';
}

$rc = new ReflectionClass('Sub');
echo 'MAX=', $rc->getConstant('MAX'), "\n";
echo 'NAME=', $rc->getConstant('NAME'), "\n";
echo 'DERIVED=', $rc->getConstant('DERIVED'), "\n";
echo 'VERSION=', $rc->getConstant('VERSION'), "\n";
echo 'EXTRA=', $rc->getConstant('EXTRA'), "\n";
echo 'SCALE=', $rc->getConstant('SCALE'), "\n";
echo 'has MIN=', $rc->hasConstant('MIN') ? '1' : '0', "\n";
echo 'has NOPE=', $rc->hasConstant('NOPE') ? '1' : '0', "\n";
$all = $rc->getConstants();
echo 'count=', count($all), "\n";
echo 'ITEMS=', implode(',', $all['ITEMS']), "\n";
