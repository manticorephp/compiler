<?php

// An object of a class the stdlib defines (DeflateContext, InflateContext,
// HashContext) or of the built-in stdClass is released when the slot holding it
// is overwritten or dies, exactly like a user class. The stdlib `.sig` exported
// no classes, so in a program `?\DeflateContext $def` named no class: the slot
// erased to unknown, owned nothing, and every overwrite and every dying holder
// kept the context (~70 KB each) forever — a WebSocket server offering
// `server_no_context_takeover` leaked one per message. `?\stdClass` erased the
// same way because the built-in class was registered only after every user
// class had been built. memory_get_usage() answers the peak RSS (ru_maxrss)
// here, which a leak can only raise. @serial: a memory measurement.

final class Holder
{
    public ?\DeflateContext $def = null;
    public ?\InflateContext $inf = null;
    public ?\HashContext $hash = null;
    public ?\stdClass $obj = null;
    public static ?\DeflateContext $sdef = null;
    public static ?\stdClass $sobj = null;
    /** @var array<int, \DeflateContext> */
    public array $slots = [];

    public function deflate(string $p): int
    {
        $x = deflate_init(ZLIB_ENCODING_RAW);
        $this->def = $x;
        return strlen(inflate_raw(deflate_add($x, $p, ZLIB_SYNC_FLUSH)));
    }

    public function inflate(string $z): int
    {
        $x = inflate_init(ZLIB_ENCODING_RAW);
        $this->inf = $x;
        return strlen(inflate_add($x, $z, ZLIB_SYNC_FLUSH));
    }

    public function hash(string $p): int
    {
        $x = hash_init('sha256');
        hash_update($x, $p);
        $this->hash = $x;
        return strlen(hash_final($x));
    }

    public function obj(int $i): int
    {
        $n = new \stdClass();
        $n->s = str_repeat('x', 4000);
        $n->i = $i;
        $this->obj = $n;
        return strlen($n->s);
    }
}

function inflate_raw(string $z): string
{
    $x = inflate_init(ZLIB_ENCODING_RAW);
    return inflate_add($x, $z, ZLIB_SYNC_FLUSH);
}

function make_deflate(): \DeflateContext
{
    $c = deflate_init(ZLIB_ENCODING_RAW);
    deflate_add($c, str_repeat('warm up the hash chains ', 40), ZLIB_SYNC_FLUSH);
    return $c;
}

/** @param array<int, \DeflateContext> $a */
function fill_contexts(array $a, int $n): int
{
    for ($i = 0; $i < $n; $i++) {
        $a[$i % 3] = make_deflate();
    }
    return count($a);
}

/** @param callable(int): int $body */
function measure(string $label, int $n, callable $body, bool $bounded = true): void
{
    $sum = 0;
    for ($i = 0; $i < 50; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $before = memory_get_usage();
    for ($i = 0; $i < $n; $i = $i + 1) {
        $sum = $sum + $body($i);
    }
    $growth = memory_get_usage() - $before;
    if (!$bounded) {
        echo $label, ': sum=', $sum, "\n";
        return;
    }
    echo $label, ': sum=', $sum, ' ', $growth < 6 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($growth / 1048576, 1) . 'MB', "\n";
}

$payload = str_repeat('hello websocket ', 40);
$z = deflate_add(deflate_init(ZLIB_ENCODING_RAW), $payload, ZLIB_SYNC_FLUSH);
$keep = new Holder();

measure('deflate property overwrite', 1500, fn(int $i): int => $keep->deflate($payload));
measure('inflate property overwrite', 1500, fn(int $i): int => $keep->inflate($z));
measure('hash property overwrite', 40000, fn(int $i): int => $keep->hash($payload));
measure('stdClass property overwrite', 20000, fn(int $i): int => $keep->obj($i));

measure('deflate holder dies', 1500, function (int $i) use ($payload): int {
    $h = new Holder();
    return $h->deflate($payload);
});
measure('inflate holder dies', 1500, function (int $i) use ($z): int {
    $h = new Holder();
    return $h->inflate($z);
});
measure('hash holder dies', 40000, function (int $i) use ($payload): int {
    $h = new Holder();
    return $h->hash($payload);
});
measure('stdClass holder dies', 20000, function (int $i): int {
    $h = new Holder();
    return $h->obj($i);
});

measure('deflate array property element', 1500, function (int $i) use ($keep): int {
    $keep->slots[$i % 3] = make_deflate();
    return count($keep->slots);
});
measure('deflate array parameter element', 3, fn(int $i): int => fill_contexts([], 500));
// A STATIC property never releases what an overwrite replaces, for ANY class
// (a user class leaks the same way): a separate root, pinned for correctness.
measure('deflate static property', 300, function (int $i): int {
    Holder::$sdef = make_deflate();
    return 1;
}, false);
measure('stdClass static property', 300, function (int $i): int {
    $o = new \stdClass();
    $o->s = str_repeat('y', 4000);
    Holder::$sobj = $o;
    return strlen(Holder::$sobj->s);
}, false);
measure('deflate returned into property', 1500, function (int $i) use ($keep): int {
    $keep->def = make_deflate();
    $d = $keep->def;
    return strlen(deflate_add($d, 'x', ZLIB_SYNC_FLUSH)) > 0 ? 1 : 0;
});
measure('deflate local only', 1500, function (int $i) use ($payload): int {
    $d = deflate_init(ZLIB_ENCODING_RAW);
    return strlen(inflate_raw(deflate_add($d, $payload, ZLIB_SYNC_FLUSH)));
});

// Read back through locals: a local co-owns what it reads, while a property
// handed straight to a call or `instanceof` is a borrow that keeps the slot
// from ever releasing what an overwrite replaces.
$d = $keep->def;
$f = $keep->inf;
$h = $keep->hash;
$o = $keep->obj;
$so = Holder::$sobj;
echo $d instanceof \DeflateContext ? 'deflate' : '-', ' ', $f instanceof \InflateContext ? 'inflate' : '-', ' ',
    $h instanceof \HashContext ? 'hash' : '-', ' ', $o->i, ' ', get_class($d), ' ', get_class($so), "\n";
