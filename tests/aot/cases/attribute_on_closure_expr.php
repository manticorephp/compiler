<?php
// Attributes on closures and arrow functions in expression position (symfony's dumped containers write
// `[#[\Closure(name: 'x', class: 'Y')] fn () => ..., 'method']`).
#[Attribute]
final class Tag { public function __construct(public string $name = '') {} }

$listeners = [
    ['console.error', [#[\Closure(name: 'console.error_listener', class: 'ErrorListener')] fn () => 'arrow', 'onError']],
    ['kernel.request', [#[\Closure(name: 'locale', class: 'LocaleListener')] fn (int $n) => $n * 2, 'onRequest']],
];
foreach ($listeners as [$event, $callable]) {
    echo $event, ' ', $callable[1], ' ', ($callable[0])(21), "\n";
}
$plain = #[Tag('a')] static function (int $x): int { return $x + 1; };
$stacked = #[Tag('b')] #[Tag('c')] fn (int $x) => $x + 2;
echo $plain(1), ' ', $stacked(1), "\n";
