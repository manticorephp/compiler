<?php

// A `mixed $cb = null` property that ALSO takes a `callable`: the slot must
// stay self-describing, so its null default reads back as null through a
// local copy (not as a bare 0 = 0.0 that then gets CALLED), and the stored
// closure survives the round trip. Http\Server::$onError is this shape.

final class Hooks
{
    private mixed $onError = null;
    private mixed $onDone = null;

    public function __construct(private string $name) {}

    public function onError(callable $fn): Hooks { $this->onError = $fn; return $this; }
    public function onDone(callable $fn): Hooks { $this->onDone = $fn; return $this; }

    public function run(int $x): string
    {
        try {
            if ($x < 0) {
                throw new \RuntimeException('neg');
            }
            $d = $this->onDone;
            var_dump($d === null, is_null($d), gettype($d));
            return $d === null ? $this->name . ':done' : $d($x);
        } catch (\Throwable $e) {
            $eh = $this->onError;
            var_dump($eh === null, $eh !== null, $this->onError === null);
            if ($eh !== null) {
                return $eh($e, $x);
            }
            return $this->name . ':canned';
        }
    }
}

$h = new Hooks('a');
echo $h->run(1), "\n";
echo $h->run(-1), "\n";
$h->onError(function (\Throwable $e, int $x): string { return 'handled ' . $e->getMessage() . ' ' . $x; });
echo $h->run(-2), "\n";
$h->onDone(function (int $x): string { return 'done ' . $x; });
echo $h->run(3), "\n";
$g = (new Hooks('b'))->onDone(function (int $x): string { return 'b ' . $x; });
echo $g->run(4), "\n";
echo $g->run(-4), "\n";
