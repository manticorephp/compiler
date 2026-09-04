<?php
/**
 * The BASE temp of a READ. `mk($i)->v` and `mkarr($i)[0]` evaluate a fresh +1,
 * take one word out of it and used to drop the container on the floor. A method
 * call's RECEIVER has been released since {@see \Compile\Debug::$rcRecvTemp};
 * this is the same rule for the base of a read
 * ({@see \Compile\Debug::$rcBaseTemp}).
 *
 *   bin/manticore compile tools/prof/basetemp.php -o /tmp/basetemp
 *   for m in newprop callprop chain arrindex strindex newmeth; do
 *     /usr/bin/time -l /tmp/basetemp $m 1000000 2>&1 | grep 'maximum resident'
 *   done
 *
 * All flat (~1 MB) with the release in place; php 8.5 sits at ~28 MB.
 */

class BtNode
{
    public int $v;

    public function __construct(int $v) { $this->v = $v; }

    public function get(): int { return $this->v; }

    public function self_(): BtNode { return $this; }
}

function bt_mk(int $i): BtNode { return new BtNode($i); }

/** @return array<int,int> */
function bt_arr(int $i): array { return [$i, $i + 1]; }

function bt_str(int $i): string { return 'v' . $i; }

function bt_run(string $mode, int $n): int
{
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($mode === 'newprop') {          // property read off a fresh object
            $s += (new BtNode($i))->v;
        } elseif ($mode === 'callprop') {
            $s += bt_mk($i)->v;
        } elseif ($mode === 'chain') {      // the fluent shape
            $s += bt_mk($i)->self_()->v;
        } elseif ($mode === 'arrindex') {   // element read off a fresh array
            $s += bt_arr($i)[0];
        } elseif ($mode === 'strindex') {   // char read off a fresh string
            $s += \strlen(bt_str($i)[0]);
        } elseif ($mode === 'newmeth') {    // the control: already released
            $s += (new BtNode($i))->get();
        }
    }
    return $s;
}

$argvv = $argv;
$mode = $argvv[1] ?? 'newprop';
$iters = (int)($argvv[2] ?? 1000000);
echo $mode, ' ', bt_run($mode, $iters), "\n";
