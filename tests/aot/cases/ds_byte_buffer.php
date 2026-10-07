<?php
use Manticore\Ds\ByteBuffer;

function attempt(callable $f): void
{
    try { var_dump($f()); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$b = new ByteBuffer(16);
$b->setUInt8(0, 0xAB);
$b->setInt8(1, -2);
$b->setUInt16(2, 0x1234);
$b->setUInt16(4, 0x1234, true);
$b->setInt32(6, -123456789);
$b->setUInt32(10, 0xDEADBEEF, true);
echo bin2hex($b->toString()), "\n";
var_dump($b->getUInt8(0), $b->getInt8(0), $b->getInt8(1), $b->getUInt16(2), $b->getUInt16(2, true), $b->getInt16(4), $b->getInt32(6), $b->getUInt32(6), $b->getUInt32(10, true), $b->getInt32(10, true));
var_dump($b[0], $b[3], count($b), $b->sum());

$w = new ByteBuffer(32);
$w->setInt64(0, PHP_INT_MIN + 5);
$w->setInt64(8, 0x0102030405060708, true);
$w->setFloat64(16, -1234.5625);
$w->setFloat32(24, 0.1);
$w->setFloat32(28, -2.5, true);
echo bin2hex($w->toString()), "\n";
var_dump($w->getInt64(0), $w->getInt64(8, true), $w->getInt64(8), $w->getFloat64(16), $w->getFloat32(24), $w->getFloat32(28, true));
foreach ([0.0, -0.0, INF, -INF, 1e-320, PHP_FLOAT_MAX, 1e39] as $v) {
    $w->setFloat64(0, $v); $w->setFloat32(8, $v);
    echo bin2hex($w->toString(0, 12)), ' ';
    var_dump($w->getFloat64(0), $w->getFloat32(8));
}
$w->setFloat64(0, NAN);
var_dump(is_nan($w->getFloat64(0)));

$s = ByteBuffer::fromString("PK\x03\x04hello\x00\xff");
var_dump(count($s), $s->getUInt32(0), $s->getUInt16(0, true), $s->toString(4, 5), $s[10], bin2hex($s->toString(9)));
$s->write(4, 'HELLO');
$s[] = 0x21;
$s->push(10);
echo bin2hex($s->toString()), ' ', count($s), ' ', $s->pop(), ' ', $s->indexOf(0x4c), "\n";
$t = clone $s;
$t->setUInt16(0, 0);
var_dump($s->equals($t), $s->getUInt16(0), $t->getUInt16(0), $s->toString(0, 0));

attempt(fn () => $b->getUInt32(13));
attempt(fn () => $b->getInt64(9, true));
attempt(fn () => $b->getUInt8(-1));
attempt(fn () => $b->getUInt16(15));
attempt(function () use ($b) { $b->setUInt16(0, 70000); return 'no'; });
attempt(function () use ($b) { $b->setInt8(0, 128); return 'no'; });
attempt(function () use ($b) { $b->setUInt32(0, -1); return 'no'; });
attempt(function () use ($b) { $b->setFloat64(9, 1.0); return 'no'; });
attempt(function () use ($b) { $b->write(14, 'abc'); return 'no'; });
attempt(fn () => $b->toString(10, 7));
attempt(fn () => $b->toString(17));
attempt(function () use ($b) { $b[0] = 256; return 'no'; });
attempt(fn () => $b->getUInt8(15));

// a counted loop over the bytes is done in place
$big = new ByteBuffer(100000);
for ($i = 0; $i < 100000; $i++) { $big[$i] = $i & 255; }
$x = 0;
for ($i = 0; $i < 100000; $i++) { $x ^= $big[$i] * ($i & 7); }
var_dump($x, $big->getUInt32(99996), $big->sum());
