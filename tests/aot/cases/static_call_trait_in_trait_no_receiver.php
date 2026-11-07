<?php
trait Inner {
    protected static array $stack = [];
    protected static function bind(?self $ctx, callable $callable): mixed {
        static::$stack[] = $ctx;
        try { return $callable(); } finally { array_pop(static::$stack); }
    }
}
trait Outer { use Inner;
    private function go(string $m, array $p): mixed {
        return static::bind($this, function () use (&$m, &$p) { return $m . ':' . implode(',', $p); });
    }
}
class K { use Outer; public function __call($m, $p) { return $this->go($m, $p); } }
echo (new K)->addMinutes(30, 5), "\n";
