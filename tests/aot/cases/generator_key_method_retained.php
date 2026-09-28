<?php
// A generator's key() through a method call hands the caller a +1, as
// current() does. Bare, the caller's owner (IteratorIterator caching
// `$this->__key = $inner->key()`) released the frame's own key, and the next
// step freed it again: symfony Finder's appended file lost its pathname.
function gen() { for ($i = 0; $i < 4; $i++) { yield 'key-' . str_repeat((string)$i, 20) => $i; } }
final class Holder {
    private mixed $key = null;
    /** @var array<int, mixed> */
    private array $seen = [];
    public function __construct(private Iterator $it) {}
    public function run(): array {
        for ($this->it->rewind(); $this->it->valid(); $this->it->next()) {
            $this->key = $this->it->key();
            $this->seen[] = $this->key;
        }
        return $this->seen;
    }
}
$keys = (new Holder(gen()))->run();
$junk = [];
for ($i = 0; $i < 200; $i++) { $junk[] = str_repeat('x', 30) . $i; }
foreach ($keys as $k) { echo $k, "\n"; }
$ai = new AppendIterator();
$ai->append(new IteratorIterator(gen()));
$all = iterator_to_array($ai);
for ($i = 0; $i < 200; $i++) { $junk[] = str_repeat('y', 30) . $i; }
echo implode(',', array_keys($all)), "\n";
