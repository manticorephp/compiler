<?php
$root = sys_get_temp_dir() . '/spltest_' . getmypid();
@mkdir("$root/a/b", 0777, true); @mkdir("$root/c", 0777, true);
file_put_contents("$root/x.txt", "12345"); file_put_contents("$root/a/y.php", "<?php"); file_put_contents("$root/a/b/z.md", "");
symlink("$root/a", "$root/link");
function run(string $P, string $root): string {
    ob_start();
    $sorted = function (iterable $it, callable $f) { $o = []; foreach ($it as $k => $v) { $o[] = $f($k, $v); } sort($o); return implode(' | ', $o); };
    $rel = fn ($p) => str_replace([(string)realpath($root), $root], 'R', (string)$p);
    // SplFileInfo
    foreach (["$root/x.txt", "$root/a/", "rel/file.tar.gz", "/", "noext", ".hidden", "$root/missing"] as $p) {
        $i = new \SplFileInfo($p);
        echo $rel($i->getPathname()), ',', $rel($i->getPath()), ',', $i->getFilename(), ',', $i->getBasename('.gz'), ',', $i->getExtension(), ',', $i->isFile() ? 'f' : '-', $i->isDir() ? 'd' : '-', ',', $rel((string)$i), "\n";
    }
    $i = new \SplFileInfo("$root/x.txt"); echo $i->getSize(), ' ', $i->getType(), ' ', $rel($i->getRealPath()), ' ', get_class($i->getPathInfo()) === 'SplFileInfo' ? 'pi' : 'x', "\n";
    try { (new \SplFileInfo("$root/missing"))->getSize(); } catch (RuntimeException $e) { echo $rel($e->getMessage()), "\n"; }
    // DirectoryIterator
    echo $sorted(new \DirectoryIterator($root), fn ($k, $v) => $v->getFilename() . ($v->isDot() ? '*' : '') . ':' . $rel($v->getPathname())), "\n";
    try { new \DirectoryIterator("$root/nope"); } catch (UnexpectedValueException $e) { echo str_replace('ZDirectory', 'Directory', $rel($e->getMessage())), "\n"; }
    // FilesystemIterator flags
    echo $sorted(new \FilesystemIterator($root), fn ($k, $v) => $rel($k) . '=>' . get_class($v)), "\n";
    echo $sorted(new \FilesystemIterator($root, \FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::CURRENT_AS_PATHNAME), fn ($k, $v) => $k . '=>' . $rel($v)), "\n";
    // Recursive
    foreach ([0, 1, 2] as $mode) {
        $rdi = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        echo $mode, ': ', $sorted(new \RecursiveIteratorIterator($rdi, $mode), fn ($k, $v) => $rel($k)), "\n";
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), 1);
    $o = []; foreach ($it as $v) { $o[] = $it->getDepth() . ':' . $it->getSubIterator()->getSubPathname(); } sort($o); echo implode(' ', $o), "\n";
    $it->setMaxDepth(0); $o = []; foreach ($it as $k => $v) { $o[] = $rel($k); } sort($o); echo implode(' ', $o), "\n";
    // Filter / callback / iteratoriterator
    $ai = new ArrayIterator(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
    foreach (new \CallbackFilterIterator($ai, fn ($v, $k) => $v % 2 === 0) as $k => $v) { echo "$k=$v "; } echo "\n";
    $ii = new \IteratorIterator(new ArrayObject([5, 6])); var_dump($ii->valid()); foreach ($ii as $k => $v) { echo "$k:$v "; } echo "\n";
    $ap = new \AppendIterator(); $ap->append(new ArrayIterator([1, 2])); $ap->append(new ArrayIterator([])); $ap->append(new ArrayIterator(['x' => 3]));
    foreach ($ap as $k => $v) { echo "$k=$v "; } echo "\n";
    // Recursive over arrays via RecursiveArrayIterator-like custom
    $tree = new class([1, [2, [3, 4]], 5]) implements \Iterator { public function __construct(public array $a, public int $p = 0) {} public function current(): mixed { return $this->a[$this->p]; } public function key(): mixed { return $this->p; } public function next(): void { $this->p++; } public function rewind(): void { $this->p = 0; } public function valid(): bool { return $this->p < count($this->a); } };
    // SplFixedArray
    $fa = new \SplFixedArray(3); $fa[0] = 'a'; $fa[2] = 'c';
    echo count($fa), ' ', $fa->getSize(), ' ', json_encode($fa->toArray()), ' ', isset($fa[1]) ? 'y' : 'n', isset($fa[2]) ? 'y' : 'n', isset($fa[9]) ? 'y' : 'n', "\n";
    foreach ($fa as $k => $v) { echo "$k=", var_export($v, true), ' '; } echo "\n";
    try { $fa[3] = 1; } catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }
    try { echo $fa['x']; } catch (\Throwable $e) { echo get_class($e), ': ', str_replace('ZSpl', 'Spl', $e->getMessage()), "\n"; }
    $fa->setSize(5); echo $fa->getSize(), json_encode($fa->toArray()), "\n"; $fa->setSize(1); echo json_encode($fa->toArray()), "\n";
    $fb = \SplFixedArray::fromArray([2 => 'x', 0 => 'y']); echo json_encode($fb->toArray()), json_encode(\SplFixedArray::fromArray(['q' => 1, 'r' => 2], false)->toArray()), "\n";
    // Queue / stack
    $q = new \SplQueue(); $q->enqueue(1); $q->enqueue(2); $q[] = 3; echo $q->dequeue(), count($q), $q->isEmpty() ? 'e' : 'n'; foreach ($q as $k => $v) { echo " $k:$v"; } echo "\n";
    $st = new \SplStack(); $st->push('a'); $st->push('b'); echo $st->top(); foreach ($st as $k => $v) { echo " $k:$v"; } echo ' ', $st->pop(), "\n";
    // SplObjectStorage
    $s = new \SplObjectStorage(); $o1 = new stdClass(); $o2 = new stdClass(); $o3 = new stdClass();
    $s->attach($o1, 'one'); $s[$o2] = 'two'; $s->attach($o3); $s->attach($o1, 'uno');
    echo count($s), ' ', $s[$o1], ' ', $s->contains($o2) ? 'y' : 'n', ' '; $s->detach($o2); echo count($s), isset($s[$o2]) ? 'y' : 'n';
    foreach ($s as $i => $obj) { echo " $i:", $obj === $o1 ? 'o1' : ($obj === $o3 ? 'o3' : '?'), '/', var_export($s->getInfo(), true); } echo "\n";
    try { $s[$o2]; } catch (UnexpectedValueException $e) { echo $e->getMessage(), "\n"; }
    return ob_get_clean();
}
echo run('', $root);
exec('rm -rf ' . escapeshellarg($root));
