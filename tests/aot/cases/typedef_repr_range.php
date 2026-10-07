<?php
// superset: no php oracle (under php a #[TypeDef] is a plain object) — expected output is written by hand
#[TypeDef(repr: 'i8')]
final class I8
{
    public function __construct(public readonly int $value) {}
    public function add(I8 $o): I8 { return new I8($this->value + $o->value); }
    public function wrappingAdd(I8 $o): I8 { return new I8((($this->value + $o->value + 128) & 0xFF) - 128); }
}
#[TypeDef(repr: 'u16')]
final class Port
{
    public readonly int $value;
    public function __construct(int $raw) { $this->value = $this($raw); }
    public function __invoke(int $raw): int { return $raw === 0 ? 65536 : $raw; }
}
#[TypeDef(repr: 'f32')]
final class F32
{
    public function __construct(public readonly float $value) {}
}
final class Holder { public function __construct(public I8 $v) {} }

function attempt(string $what, callable $f): void
{
    try { $r = $f(); echo $what, ': ', $r, "\n"; } catch (Throwable $e) { echo $what, ': ', get_class($e), ': ', $e->getMessage(), "\n"; }
}

attempt('in range', fn () => (new I8(-128))->value . ' ' . (new I8(127))->value);
attempt('new I8(300)', fn () => (new I8(300))->value);
attempt('new I8(-129)', fn () => (new I8(-129))->value);
attempt('add 100+100', fn () => (new I8(100))->add(new I8(100))->value);
attempt('wrapping add 100+100', fn () => (new I8(100))->wrappingAdd(new I8(100))->value);
attempt('property 300', fn () => (new Holder(new I8(300)))->v->value);
attempt('property 99', fn () => (new Holder(new I8(99)))->v->value);
attempt('port 8080', fn () => (new Port(8080))->value);
attempt('port 0 (normaliser result checked)', fn () => (new Port(0))->value);
attempt('f32 0.1', fn () => sprintf('%.17g', (new F32(0.1))->value));
attempt('f32 1e39', fn () => (new F32(1e39))->value);
attempt('f32 -INF', fn () => (new F32(-INF))->value);
attempt('f32 NAN', fn () => is_nan((new F32(NAN))->value) ? 'nan' : 'num');
