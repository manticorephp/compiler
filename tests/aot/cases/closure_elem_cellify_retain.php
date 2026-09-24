<?php
final class Builder {
    private ?array $allowedValues = null;
    /** @param non-empty-list<null|(callable(mixed): bool)|scalar> $allowedValues */
    public function setAllowedValues(array $allowedValues): self { $this->allowedValues = $allowedValues; return $this; }
    public function count(): int { return \count($this->allowedValues ?? []); }
    public function run(array $v): bool {
        foreach ($this->allowedValues ?? [] as $a) { if ($a instanceof \Closure && !$a($v)) { return false; } }
        return true;
    }
}
function make(): array {
    $asserts = [static function (array $values): bool {
        foreach ($values as $value) { if ('' === $value) { return false; } }
        return true;
    }];
    $out = [];
    for ($i = 0; $i < 4; $i++) { $out[] = (new Builder())->setAllowedValues($asserts); }
    $out[] = (new Builder())->setAllowedValues(['a', 'b', 3]);
    return $out;
}
for ($r = 0; $r < 3; $r++) {
    $bs = make();
    $junk = [];
    for ($j = 0; $j < 256; $j++) { $junk[] = str_repeat(chr(65 + $j % 26), 8 + $j % 9); $junk[] = static fn () => $j; }
    foreach ($bs as $b) { echo $b->count(), $b->run(['a']) ? 'T' : 'F', $b->run(['']) ? 'T' : 'F', ' '; }
    echo "\n";
}
