<?php
final class Tok { public function __construct(public string $s) {} public function __destruct() { echo "~Tok {$this->s}\n"; } }
/** @return array{int, array<int, array{classIndex: int, token: Tok, type: string}>} */
function find(int $ci, bool $has): array {
    $elements = [];
    if ($has) { $elements[$ci + 1] = ['classIndex' => $ci, 'token' => new Tok("t$ci"), 'type' => 'method']; }
    return [$ci + 5, $elements];
}
/** @return array<int, array{classIndex: int, token: Tok, type: string}> */
function get(): array {
    $elements = [];
    foreach ([[1, true], [10, false], [20, false]] as [$ci, $has]) {
        [$idx, $new] = find($ci, $has);
        $elements += $new;
    }
    ksort($elements);
    return $elements;
}
for ($k = 0; $k < 3; $k++) {
    foreach (array_reverse(get(), true) as $i => $el) { echo $i, ' ', $el['type'], "\n"; }
}
echo "end\n";
