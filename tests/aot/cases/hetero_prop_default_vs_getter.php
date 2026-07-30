<?php
// A getter whose declared return type names ONE element class retyped a whole
// heterogeneous property: `style(): ?Style { return $this->options['style']; }`
// turned `['rowspan' => 1, 'colspan' => 1, 'style' => null]` into
// vec[Style], and the boxed int 1 was then rc-retained as an object pointer.
// A whole-array store into the slot is the stronger statement and now wins.
class Style { public function __construct(public string $n) {} }
class Cell {
    private string $value;
    private array $options = [
        'rowspan' => 1,
        'colspan' => 1,
        'style' => null,
    ];
    public function __construct(string $value = '', array $options = [])
    {
        $this->value = $value;
        if ($diff = \array_diff(\array_keys($options), \array_keys($this->options))) {
            throw new \InvalidArgumentException('bad: ' . \implode(',', $diff));
        }
        if (isset($options['style']) && !$options['style'] instanceof Style) {
            throw new \InvalidArgumentException('style');
        }
        $this->options = \array_merge($this->options, $options);
    }
    public function colspan(): int { return (int) $this->options['colspan']; }
    public function style(): ?Style { return $this->options['style']; }
    public function __toString(): string { return $this->value; }
}
$c = new Cell('hi');
echo 'colspan=', $c->colspan(), ' style=', $c->style() === null ? 'null' : 'set', " v=", (string) $c, "\n";
$d = new Cell('yo', ['colspan' => 3, 'style' => new Style('bold')]);
echo 'colspan=', $d->colspan(), ' style=', $d->style() === null ? 'null' : $d->style()->n, "\n";
try { new Cell('x', ['nope' => 1]); } catch (\InvalidArgumentException $e) { echo 'caught: ', $e->getMessage(), "\n"; }
