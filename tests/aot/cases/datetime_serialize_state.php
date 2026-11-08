<?php
// A date's (array), __serialize and __unserialize speak php's {date, timezone_type, timezone} state.
$d = new DateTimeImmutable("2026-01-02 03:04:05.5", new DateTimeZone("Europe/Kyiv"));
print_r((array)$d);
$s = serialize($d); echo $s, "\n";
var_dump(unserialize($s) == $d);
echo unserialize($s)->format('Y-m-d H:i:s.u e'), "\n";
$m = new DateTime("2020-07-01 10:00:00", new DateTimeZone("America/New_York"));
print_r((array)$m); print_r($m->__serialize());
echo serialize($m), "\n", unserialize(serialize($m))->format(DATE_ATOM), "\n";
$z = new DateTime;
$z->__unserialize(["date" => "2020-02-03 04:05:06.000007", "timezone_type" => 1, "timezone" => "+02:00"]);
echo $z->format('Y-m-d\TH:i:s.uP U'), "\n";
$st = DateTimeImmutable::__set_state(["date" => "2020-02-03 04:05:06.000000", "timezone_type" => 2, "timezone" => "EET"]);
echo get_class($st), ' ', $st->format("c T U"), "\n";
$t = new DateTimeZone("Asia/Tokyo");
print_r($t->__serialize()); print_r((array)$t); echo serialize($t), "\n", unserialize(serialize($t))->getName(), "\n";
echo DateTimeZone::__set_state(['timezone_type' => 1, 'timezone' => '-03:00'])->getName(), "\n";
try { (new DateTimeImmutable)->__unserialize(['date' => 1]); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
final class Point extends DateTimeImmutable {
  public function __construct(string $s) { $n = new DateTimeImmutable($s, new DateTimeZone('UTC')); $this->__unserialize((array)$n); } }
$p = new Point('2024-03-04 05:06:07.25');
echo get_class($p), ' ', $p->format('c u'), ' ', $p->modify('+1 day')->format('Y-m-d'), "\n";
function arr(mixed $o): array { return (array)$o; }
echo implode('|', arr($p)), "\n";
