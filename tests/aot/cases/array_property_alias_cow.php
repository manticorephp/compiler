<?php
// `$tokens = $this->tokens;` then a mutation must not touch the property —
// PHP arrays are VALUES. The alias-retain existed but covered only ASSOC
// properties, and a bare `array` hint erases to KIND_UNKNOWN so isArray() also
// missed it; with the refcount stuck at 1 the mutation's copy-on-write saw a
// unique buffer and wrote straight through.
//
// symfony's ArgvInput::getParameterOption does exactly this and drained
// $this->tokens, then freed a buffer the property still held (invalid free).
class Tokens
{
    private array $items = [];
    private array $assoc = [];
    public function __construct(array $t) { $this->items = $t; $this->assoc = ['a' => 1, 'b' => 2]; }
    public function count_(): int { return \count($this->items); }
    public function assocCount(): int { return \count($this->assoc); }

    public function drainShift(): int
    {
        $local = $this->items;
        $n = 0;
        while (0 < \count($local)) { \array_shift($local); $n = $n + 1; }
        return $n;
    }
    public function drainPop(): int
    {
        $local = $this->items;
        $n = 0;
        while (0 < \count($local)) { \array_pop($local); $n = $n + 1; }
        return $n;
    }
    public function mutateAssoc(): int
    {
        $snap = $this->assoc;
        \array_shift($snap);
        return \count($snap);
    }
}
$t = new Tokens(['x', 'y', 'z']);
echo 'before=', $t->count_(), "\n";
echo 'shifted=', $t->drainShift(), ' after=', $t->count_(), "\n";
echo 'popped=', $t->drainPop(), ' after=', $t->count_(), "\n";
echo 'again=', $t->drainShift(), ' after=', $t->count_(), "\n";
echo 'assoc snap=', $t->mutateAssoc(), ' prop=', $t->assocCount(), "\n";
