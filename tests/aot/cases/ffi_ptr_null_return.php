<?php
use Ffi\Library;
use Ffi\Symbol;
use Ffi\Ptr;

#[Library('c'), Symbol('strchr')]
function strchr_(string $s, #[\Ffi\CType('int')] int $c): Ptr { return Ptr::null(); }

$p = strchr_('hello', 120);
var_dump($p->isNull());
var_dump(strchr_('hello', 108)->isNull());
