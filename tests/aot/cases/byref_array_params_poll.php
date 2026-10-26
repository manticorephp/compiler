<?php
// A static method's by-ref ?array parameters holding objects are rewritten in place.
final class Conn { public function __construct(public int $id) {} }
final class Poller {
    /** @param ?array<int, Conn> $read @param ?array<int, Conn> $error @param array<int, Conn> $reject */
    public static function poll(?array &$read, ?array &$error, array &$reject, int $seconds, int $microseconds = 0): int|false {
        $ready = [];
        foreach ($read ?? [] as $c) { if ($c->id % 2 === 0) { $ready[] = $c; } else { $reject[] = $c; } }
        $read = $ready;
        $error = [];
        return count($ready);
    }
}
$read = [new Conn(1), new Conn(2), new Conn(4)];
$error = $read;
$reject = [];
var_dump(Poller::poll($read, $error, $reject, 0));
foreach ($read as $c) { echo "read ", $c->id, "\n"; }
foreach ($reject as $c) { echo "reject ", $c->id, "\n"; }
var_dump($error);
$n = null; $e = null; $r = [];
var_dump(Poller::poll($n, $e, $r, 0, 10));
var_dump($n, $e, $r);
