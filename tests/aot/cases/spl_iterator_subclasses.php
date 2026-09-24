<?php
$root = sys_get_temp_dir() . '/spl2_' . getmypid();
@mkdir("$root/src/Sub", 0777, true); @mkdir("$root/vendor/x", 0777, true);
foreach (["src/A.php", "src/Sub/B.php", "src/readme.md", "vendor/x/C.php"] as $f) { file_put_contents("$root/$f", "<?php // $f"); }
class ExtFilter extends \FilterIterator {
    public function __construct(\Iterator $it, private string $ext) { parent::__construct($it); }
    public function accept(): bool { return str_ends_with($this->current()->getFilename(), $this->ext); }
}
class ExcludeDir extends \RecursiveFilterIterator {
    public function accept(): bool { return $this->current()->getFilename() !== 'vendor'; }
}
class FinderRdi extends \RecursiveDirectoryIterator {
    private bool $ignoreFirstRewind = true;
    private string $rootPath;
    public function __construct(string $path, int $flags, bool $ign = false) { parent::__construct($path, $flags); $this->rootPath = $path; }
    public function current(): string { return 'F:' . $this->getSubPathname(); }
    public function getChildren(): \RecursiveDirectoryIterator { $c = parent::getChildren(); if ($c instanceof self) { $c->rootPath = $this->rootPath; } return $c; }
    public function next(): void { $this->ignoreFirstRewind = false; parent::next(); }
    public function rewind(): void { if ($this->ignoreFirstRewind) { $this->ignoreFirstRewind = false; return; } parent::rewind(); }
}
class Toks extends \SplFixedArray {
    public function offsetSet(mixed $i, mixed $v): void { parent::offsetSet($i, strtoupper($v)); }
    public static function fromArray(array $a, bool $pk = true): static { $t = new static(count($a)); foreach ($a as $i => $v) { $t[$i] = $v; } return $t; }
}
class Node implements \RecursiveIterator {
    private int $p = 0;
    public function __construct(private array $kids) {}
    public function current(): mixed { return $this->kids[$this->p][0]; }
    public function key(): mixed { return $this->p; }
    public function next(): void { $this->p++; }
    public function rewind(): void { $this->p = 0; }
    public function valid(): bool { return $this->p < count($this->kids); }
    public function hasChildren(): bool { return count($this->kids[$this->p][1] ?? []) > 0; }
    public function getChildren(): \RecursiveIterator { return new self($this->kids[$this->p][1]); }
}
function run2(string $P, string $root): string {
    ob_start();
    $rel = fn ($p) => str_replace([(string)realpath($root), $root], 'R', (string)$p);
    $flags = \FilesystemIterator::SKIP_DOTS;
    $rii = new \RecursiveIteratorIterator(new \ExcludeDir(new \RecursiveDirectoryIterator($root, $flags)), 1);
    $o = []; foreach (new \ExtFilter($rii, '.php') as $k => $v) { $o[] = $rel($k); } sort($o); echo implode(' ', $o), "\n";
    $o = []; foreach (new \RecursiveIteratorIterator(new \FinderRdi($root, $flags), 1) as $k => $v) { $o[] = $v; } sort($o); echo implode(' ', $o), "\n";
    $t = \Toks::fromArray(['a', 'b']); echo get_class($t) === 'Toks' ? 'T' : 'x', $t[0], $t[1], count($t), "\n";
    $tree = new \Node([['root', [['a', [['a1'], ['a2']]], ['b']]], ['r2']]);
    foreach (new \RecursiveTreeIterator($tree) as $k => $line) { echo $line, "\n"; }
    $rt = new \RecursiveTreeIterator($tree); $rt->setPrefixPart(\RecursiveTreeIterator::PREFIX_LEFT, '>');
    foreach ($rt as $line) { echo $line, "\n"; }
    foreach ([0, 1, 2] as $m) { echo $m, ':'; foreach (new \RecursiveIteratorIterator($tree, $m) as $v) { echo ' ', $v; } echo "\n"; }
    $cb = new \RecursiveCallbackFilterIterator($tree, fn ($c, $k, $it) => $c !== 'a');
    foreach (new \RecursiveIteratorIterator($cb, 1) as $v) { echo $v, ' '; } echo "\n";
    $e = new \EmptyIterator(); var_dump($e->valid());
    $di = new \DirectoryIterator("$root/src"); $names = []; foreach ($di as $f) { if (!$f->isDot()) $names[] = $f->getFilename(); } sort($names); echo implode(',', $names), "\n";
    return ob_get_clean();
}
echo run2('', $root);
exec('rm -rf ' . escapeshellarg($root));
