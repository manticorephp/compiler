<?php
// An FFI binding declared to return \Ffi\Ptr gives PHP null for a C NULL, so ->isNull() is a fatal error
// issue: #401
use Ffi\Library;
use Ffi\Symbol;
use Ffi\Ptr;

#[Library('c'), Symbol('strchr')]
function strchr_(string $s, #[\Ffi\CType('int')] int $c): Ptr { return Ptr::null(); }

$p = strchr_('hello', 120);
var_dump($p->isNull());
