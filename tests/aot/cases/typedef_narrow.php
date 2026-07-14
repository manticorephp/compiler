<?php
#[TypeDef(repr: 'u8')]
final class U8 { public function __construct(public readonly int $value) {} }

#[TypeDef(repr: 'i32')]
final class I32 { public function __construct(public readonly int $value) {} }

#[TypeDef(repr: 'f32')]
final class F32 { public function __construct(public readonly float $value) {} }

final class Pixel {
    public function __construct(
        public U8 $r,
        public U8 $g,
        public U8 $b,
        public U8 $a,
    ) {}
}

final class Mixed_ {
    public function __construct(
        public U8 $tag,
        public I32 $id,
        public F32 $ratio,
        public string $name,
    ) {}
}

$p = new Pixel(new U8(255), new U8(128), new U8(64), new U8(255));
echo $p->r->value, ",", $p->g->value, ",", $p->b->value, ",", $p->a->value, "\n";

$m = new Mixed_(new U8(7), new I32(-123456), new F32(0.5), "hi");
echo $m->tag->value, " ", $m->id->value, " ", $m->ratio->value, " ", $m->name, "\n";

$m2 = new Mixed_(new U8(200), new I32(2147483647), new F32(-1.25), "x");
echo $m2->tag->value, " ", $m2->id->value, " ", $m2->ratio->value, "\n";
