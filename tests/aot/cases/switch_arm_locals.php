<?php
class Q { public function __construct(private string|bool|int|float|null $d, private bool $multi) {} public function getDefault(): string|bool|int|float|null { return $this->d; } public function isMulti(): bool { return $this->multi; } }
function esc(string $t): string { return '<' . $t . '>'; }
function w(Q $q): string {
    $default = $q->getDefault();
    switch (true) {
        case null === $default:
            return 'none';
        case $q->isMulti():
            $default = explode(',', $default);
            foreach ($default as $key => $value) { $default[$key] = strtoupper(trim($value)); }
            return implode('|', $default);
        default:
            return esc($default);
    }
}
echo w(new Q('a, b', true)), ' ', w(new Q('x', false)), ' ', w(new Q(null, false)), "\n";
