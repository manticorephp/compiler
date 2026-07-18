<?php
final class T {
    public function __construct(public readonly string $kind) {}
    public function unionWith(T $o): T { return $this->kind === $o->kind ? $this : new T('unknown'); }
}
/** @param T[] $items */
function joinTyped(array $items): string {
    $elem = null;
    foreach ($items as $t) {
        $elem = $elem === null ? $t : $elem->unionWith($t);
    }
    return $elem === null ? 'NONE' : $elem->kind;
}
var_dump(joinTyped([new T('cell'), new T('string')]));   // php: unknown
var_dump(joinTyped([new T('string'), new T('cell')]));   // php: unknown
var_dump(joinTyped([new T('string'), new T('string')])); // php: string
echo "done\n";
