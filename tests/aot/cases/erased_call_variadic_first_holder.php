<?php
// The first declarer of `addTag` is variadic; the receiver is a union, so the site must not be trimmed to ITS arity.
namespace B { class Tag { public function __construct(public string $n = '') {} } class Post { public array $tags = []; public function addTag(Tag ...$tags): void { foreach ($tags as $t) { $this->tags[] = $t->n; } } } }
namespace A { class Definition { private array $tags = []; public function addTag(string $name, array $attributes = []): static { $this->tags[$name][] = $attributes; return $this; } public function getTags(): array { return $this->tags; } } class Alias {} }
namespace {
use A\Definition; use A\Alias; use B\Post; use B\Tag;
function callAdd(Definition|Alias|null $d, string $n, array $a): void { $d->addTag($n, $a); }
function addTo(object $o, string $n, array $a): void { $o->addTag($n, $a); }
$d = new Definition();
callAdd($d, 'x', ['event' => 'e']);
addTo($d, 'y', ['event' => 'f', 'priority' => 3]);
echo json_encode($d->getTags()), "\n";
$p = new Post(); $p->addTag(new Tag('t1'), new Tag('t2')); echo json_encode($p->tags), "\n";
}
