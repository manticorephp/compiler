<?php

// SensitiveParameterValue is a real value class; #[SensitiveParameter] is an
// inert marker here — Manticore's stack traces carry no argument values at all,
// so there is nothing to redact.

function login(#[\SensitiveParameter] string $password, string $user): string
{
    return $user . ':' . strlen($password);
}

echo login('hunter2', 'bob'), "\n";

$v = new SensitiveParameterValue('s3cret');
var_dump($v->getValue());
var_dump($v->__debugInfo());

$n = new SensitiveParameterValue(42);
var_dump($n->getValue());
