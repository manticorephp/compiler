<?php
// Http\Headers: case-insensitive lookup on top of an ordered list of wire lines.

use Http\Headers;

$h = new Headers();
$h->set('Content-Type', 'text/plain');
$h->add('Set-Cookie', 'a=1');
$h->add('Set-Cookie', 'b=2');   // add() keeps both lines on the wire
$h->add('Accept', 'text/html');
$h->add('Accept', 'application/json');

echo $h->get('content-type'), "\n";            // lookup ignores case
echo $h->get('SET-COOKIE'), "\n";              // repeats are comma-joined for lookup
echo var_export($h->has('X-Missing'), true), "\n";
echo $h->get('X-Missing', 'fallback'), "\n";
echo $h->int('Content-Length', -1), "\n";      // absent: the default

$h->set('Content-Length', '42');
echo $h->int('content-length'), "\n";
echo $h->count(), " names\n";

$h->set('Accept', '*/*');                      // set() replaces every earlier line
$h->remove('Content-Type');
print_r($h->lines());                          // wire lines, in order
echo str_replace("\r\n", "|", $h->render()), "\n";

$parsed = Headers::fromLines(['Host: example.com', 'X-Trace:  abc  ', 'not a header line']);
echo $parsed->get('host'), ' / [', $parsed->get('x-trace'), "]\n";
