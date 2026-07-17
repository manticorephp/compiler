<?php
function gen(): iterable {
    yield 1;
    yield "k" => 2;
    yield from [3, 4];
}
function drain(): int {
    $t = 0;
    foreach (gen() as $v) { $t += $v; }
    return $t;
}
echo drain();
