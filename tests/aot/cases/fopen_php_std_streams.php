<?php
// symfony's ConsoleOutput opens `fopen('php://stdout', 'w')`; the wrapper was
// unhandled, fopen returned false, and StreamOutput's is_resource() check threw
// "The StreamOutput class needs a stream as its first argument."
//
// NOTE: php hands out a DISTINCT resource per fopen of a std stream; ours are
// cached per stream (one \Resource for STDOUT, id stable), so identity between
// two opens is deliberately not asserted here.
function describe($s, string $label): void
{
    echo $label, ' is_resource=', (\is_resource($s) ? 'Y' : 'n'),
         ' type=', (\is_resource($s) ? \get_resource_type($s) : '-'),
         ' gettype=', \gettype($s), "\n";
}
$out = \fopen('php://stdout', 'w');
$outUpper = \fopen('PHP://STDOUT', 'w');
$outAlias = \fopen('php://output', 'w');
$err = \fopen('php://stderr', 'w');
describe($out, 'stdout');
describe($outUpper, 'STDOUT-upper');
describe($outAlias, 'output');
describe($err, 'stderr');
// A usable write handle that shares echo's stream, so ordering holds.
\fwrite($out, "via fwrite\n");
echo "via echo\n";
// php://memory keeps its own in-memory behaviour.
$mem = \fopen('php://memory', 'w+');
describe($mem, 'memory');
\fwrite($mem, 'abc');
\rewind($mem);
var_dump(\fread($mem, 3));
