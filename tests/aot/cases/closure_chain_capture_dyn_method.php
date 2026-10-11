<?php
// closure chain capturing previous closure calling a dynamic method segfaults
class Proto {
    public array $log = [];
    public function setK0(mixed $v): static { $this->log[] = 'k0=' . (string)$v; return $this; }
    public function setK1(mixed $v): static { $this->log[] = 'k1=' . (string)$v; return $this; }
}
function build(mixed $prototype, array $keys): mixed {
    $g = static fn () => clone $prototype;
    foreach ($keys as $key) {
        $g = static fn () => $g()->{'set' . $key}(7);
    }
    return $g();
}
echo implode(',', build(new Proto(), ['K0', 'K1'])->log), "\n";
