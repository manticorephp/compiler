<?php
class Ref { public function __construct(protected string $id) {} public function __toString(): string { return $this->id; } }
class TRef extends Ref { public function __construct(string $id, public ?string $type = null) { parent::__construct($id); } }
final class CB {
    public function getDefinition(string $id): string { return "def:" . $id; }
}
function build(array $in): array {
    $refs = [];
    foreach ($in as [$index, $sid, $class]) {
        $ref = match (true) {
            !$class => new Ref($sid),
            $index === $sid => new TRef($sid, $class),
            default => new TRef($sid, $class),
        };
        if (null === $index) { $refs[] = $ref; } else { $refs[$index] = $ref; }
    }
    return $refs;
}
$c = new CB();
foreach (build([[null, 'a', null], ['b', 'b', 'K'], ['c', 'cc', 'K']]) as $id) {
    echo $c->getDefinition($id), "\n";
}
