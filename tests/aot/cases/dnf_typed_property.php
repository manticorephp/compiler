<?php
// DNF-typed property (regression guard for the asymmetric-visibility parser).
interface Drawable { public function draw(): string; }
interface Sized { public function size(): int; }
class Widget implements Drawable, Sized {
    public function draw(): string { return "widget"; }
    public function size(): int { return 3; }
}
class Canvas {
    public (Drawable&Sized)|null $item = null;
}
$c = new Canvas();
echo ($c->item === null ? "empty" : "set"), "\n";
$c->item = new Widget();
echo $c->item->draw(), " ", $c->item->size(), "\n";
