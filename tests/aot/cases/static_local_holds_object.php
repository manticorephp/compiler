<?php
// An uninitialised `static $x;` is hard-lowered `int`, and its value outlives
// the call — so the read that OPENS the next call is not reachable from the
// store that filled it and kept the int type. The second caller got the
// resource POINTER as an integer, and symfony's StreamOutput is_resource()
// check threw "The StreamOutput class needs a stream as its first argument."
//
// The slot is also global-backed: the module cell owns the value, so a
// scope-exit release of it is a pure over-release (two such cells aliasing the
// one cached STDOUT trapped at teardown).
class Out
{
    private function openOutputStream()
    {
        static $stdout;
        if ($stdout) { return $stdout; }
        if (!\defined('STDOUT')) {
            return $stdout = @\fopen('php://stdout', 'w') ?: \fopen('php://output', 'w');
        }
        return $stdout = \STDOUT;
    }
    public function get() { return $this->openOutputStream(); }
}
$o = new Out();
$s = $o->get();
echo "defined(STDOUT)=", (\defined('STDOUT') ? 'Y' : 'n'), "\n";
echo "is_resource=", (\is_resource($s) ? 'Y' : 'n'), " gettype=", \gettype($s), "\n";
$s2 = $o->get();
echo "2nd is_resource=", (\is_resource($s2) ? 'Y' : 'n'), " gettype=", \gettype($s2), "\n";

function f() { static $h; if ($h) { return $h; } return $h = \STDOUT; }
$a = f(); $b = f();
echo "fn1=", (\is_resource($a) ? 'Y' : 'n'), " fn2=", (\is_resource($b) ? 'Y' : 'n'), "\n";

// A static local holding a STRING must survive the same round trip.
function g(): string { static $t; if ($t) { return $t; } return $t = 'cached' . '!'; }
echo g(), ' ', g(), "\n";

// …and an ARRAY, which is where the element repr also has to hold.
function h(): array { static $rows; if ($rows) { return $rows; } return $rows = ['a' => 1, 'b' => 2]; }
var_dump(h());
var_dump(h());
