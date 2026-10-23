<?php

use Ffi\Library;
use Ffi\Symbol;
use Ffi\CType;

#[Library('c'), Symbol('atoi'), CType('int')]
function c_atoi(string $s): int { return 0; }

#[Library('c'), Symbol('strlen'), CType('size_t')]
function c_strlen(string $s): int { return 0; }

#[Library('c'), Symbol('htonl'), CType('uint')]
function c_htonl(#[CType('uint')] int $x): int { return 0; }

echo c_atoi("1234"), "\n";
echo c_atoi("-7abc"), "\n";
echo c_strlen("manticore"), "\n";
echo c_htonl(0xFFFFFFFF), "\n";
