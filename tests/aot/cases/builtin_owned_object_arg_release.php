<?php
final class K { public function __construct(public string $t) {} public function __destruct() { echo "~K {$this->t}\n"; } }
function mk(string $t): K { return new K($t); }
function mm(string $t): mixed { return new K($t); }
final class N { private static function key(mixed $o): object { return $o; } public static function id(mixed $o): int { return \spl_object_id(self::key($o)); } }
echo spl_object_id(mk('id')) > 0 ? "id\n" : "";
echo spl_object_id(mm('idm')) > 0 ? "idm\n" : "";
$x = new K('key'); echo N::id($x) > 0 ? "n\n" : ""; unset($x);
echo "end\n";
