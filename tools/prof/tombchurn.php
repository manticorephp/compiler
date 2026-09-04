<?php
/**
 * Tombstone CHURN — the shape that picks {@see \Compile\Debug::$tombRatio}.
 *
 * `tools/prof/elemleak.php` churns ONE key in an otherwise empty array, so the
 * entry buffer never holds live entries and every threshold behaves alike. The
 * threshold only matters when compaction has real work to move: L live entries
 * plus a stream of holes. Compaction is O(len) and the append path is the only
 * thing that triggers it, so a threshold that is too eager can compact on
 * nearly every insert — O(L) per insert, quadratic overall.
 *
 *   bin/manticore compile tools/prof/tombchurn.php -o /tmp/tombchurn
 *   for m in hot churn int; do /usr/bin/time -l /tmp/tombchurn $m 200000 20000; done
 *
 * php 8.5 is flat in memory and linear in time for all three.
 */

function tc_run(string $mode, int $iters, int $live): int
{
    $sink = 0;
    if ($mode === 'int') {
        /** @var array<int,int> $v */
        $v = [];
        for ($i = 0; $i < $live; $i++) {
            $v[$i] = $i;
        }
        for ($i = 0; $i < $iters; $i++) {
            $k = $i % $live;
            unset($v[$k]);
            $v[$k] = $i;
            $sink += $v[$k];
        }
        return $sink + \count($v);
    }
    /** @var array<string,int> $a */
    $a = [];
    for ($i = 0; $i < $live; $i++) {
        $a['k' . $i] = $i;
    }
    if ($mode === 'hot') {
        // One hot key over a large live set: every cycle leaves one hole.
        for ($i = 0; $i < $iters; $i++) {
            $a['hot'] = $i;
            $sink += $a['hot'];
            unset($a['hot']);
        }
    } else {
        // Cache shape: evict an existing key, re-insert it.
        for ($i = 0; $i < $iters; $i++) {
            $k = 'k' . ($i % $live);
            unset($a[$k]);
            $a[$k] = $i;
            $sink += $a[$k];
        }
    }
    return $sink + \count($a);
}

$argvv = $argv;
$mode = $argvv[1] ?? 'hot';
$iters = (int)($argvv[2] ?? 200000);
$live = (int)($argvv[3] ?? 20000);
echo $mode, ' ', $iters, '/', $live, ' sink=', tc_run($mode, $iters, $live), "\n";
