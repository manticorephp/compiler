<?php
class Box { public ?Box $next = null; public int $v = 5; }
$b = new Box();
echo isset($b->next) ? "1" : "0";       // 0 (next is null)
echo isset($b->v) ? "1" : "0";          // 1
$n = $b->next;                          // null
echo isset($n->v) ? "1" : "0";          // 0 (isset on null obj prop)
echo isset($n->next) ? "1" : "0";       // 0
$arr = [];
echo isset($arr[0]) ? "1" : "0";        // 0 (empty vec)
echo isset($arr["k"]) ? "1" : "0";      // 0 (string key on empty)
echo "\n";
