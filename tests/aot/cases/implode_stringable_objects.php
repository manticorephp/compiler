<?php
// implode() over Stringable objects joins their __toString(), not their
// addresses (php-cs-fixer's DocBlock::getContent).
final class Line { public function __construct(private string $c) {} public function __toString(): string { return $this->c; } }
final class Doc {
    /** @var list<Line> */
    private array $lines = [];
    public function __construct(string $s) { foreach (explode("\n", $s) as $l) { $this->lines[] = new Line($l . "\n"); } }
    public function getContent(): string { return implode('', $this->lines); }
}
echo (new Doc("/**\n * a\n */"))->getContent();
echo implode(',', [new Line('x'), new Line('y')]), "\n";
$mix = [new Line("p"), 2, "q", 1.5, null, true];
echo implode("|", $mix), "
";
