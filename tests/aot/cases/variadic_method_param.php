<?php
// A variadic param in a METHOD (instance or static) must be typed vec[T] like
// the plain-function path, AND a variable-receiver call must pack its trailing
// args into that vec — else `$xs` reads a single arg, not the packed vec.
class Log {
    /** @var string[] */ public array $lines = [];
    public function add(string ...$msgs): void { foreach ($msgs as $m) { $this->lines[] = $m; } }
    public static function join(string $sep, string ...$parts): string { return implode($sep, $parts); }
    public function head(string $first, int ...$rest): string { return $first . ':' . array_sum($rest); }
    public function count_(string ...$xs): int { return count($xs); }
}
$l = new Log();
$l->add("a", "b", "c");                          // variable receiver, variadic pack
echo count($l->lines), " ", implode(",", $l->lines), "\n";
echo Log::join("-", "x", "y", "z"), "\n";        // static variadic + leading fixed
echo $l->head("n", 1, 2, 3), "\n";               // instance, fixed + int variadic
echo $l->count_("p", "q"), "\n";                 // variadic directly consumed

// Spread into a method variadic.
$nums = [10, 20, 30];
class M { public function sum(int ...$xs): int { return array_sum($xs); } }
echo (new M())->sum(...$nums), "\n";
