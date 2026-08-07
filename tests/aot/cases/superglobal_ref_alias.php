<?php

// `$s = &$_SESSION;` is a function's WHOLE use of the superglobal, and a
// reference node names its participants as plain strings — no LoadLocal, no
// StoreLocal. The demand scan that binds a superglobal to its module cell read
// only those two, so the name stayed unbound and the program was refused with
// `MIR.verify: dangling local $_SESSION read`.
// symfony/http-foundation NativeSessionStorage::loadSession is exactly this.

function fill(): void
{
    $s = &$_SESSION;
    $s['k'] = 1;
}

function bump(): void
{
    $s = &$_SESSION;
    $s['n'] = ($s['n'] ?? 0) + 41;
}

fill();
bump();
bump();
var_dump($_SESSION);

// The alias writes through to the one cell every scope shares.
$top = &$_SESSION;
$top['from_top'] = 'yes';
var_dump($_SESSION['from_top']);
var_dump(count($_SESSION));
