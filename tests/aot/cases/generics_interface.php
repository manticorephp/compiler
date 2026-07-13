<?php
/** @template T */
interface Coll {
    /** @param T $x */ public function add($x): void;
    /** @return T */ public function get(int $i);
}
/**
 * @template T
 * @implements Coll<T>
 */
final class ListColl implements Coll {
    /** @var T[] */ private array $items = [];
    /** @param T $x */ public function add($x): void { $this->items[] = $x; }
    /** @return T */ public function get(int $i) { return $this->items[$i]; }
}
/** @var Coll<string> $c */
$c = new ListColl();
$c->add('ab');
echo $c->get(0) . '!', "\n";
/** @var Coll<float> $d */
$d = new ListColl();
$d->add(1.5);
echo $d->get(0) + 0.25, "\n";
