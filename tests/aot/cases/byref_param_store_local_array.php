<?php
// A by-ref parameter stores an owned local's array: the caller's storage takes
// its own count, so the local's drop at exit leaves the caller a live array.
function fill(array &$message, int $got)
{
    /** @var string[] $iov */
    $iov = [];
    $iov[] = str_repeat('x', $got);
    /** @var array<string,mixed> $out */
    $out = ['name' => null, 'iov' => $iov, 'flags' => 0];
    $message = $out;
    return $got;
}
/** @param array<string,mixed> $m */
function keep(array &$m): void
{
    $copy = ['k' => str_repeat('y', 3)];
    $m = $copy;
    $copy['k'] = 'z';
    echo $copy['k'], "\n";
}
$r = ['iov' => [null], 'name' => []];
var_dump(fill($r, 12));
var_dump($r['iov'][0]);
$r2 = [];
keep($r2);
var_dump($r2['k']);
