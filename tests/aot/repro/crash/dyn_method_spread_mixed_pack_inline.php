<?php
// A dynamic method call that spreads a mixed argument pack into an in-scope private method segfaults.
// issue: #168
class Runner {
    private function secret(int $a, int $b): int { return $a * $b; }
    public function viaThis(string $m, mixed $args): mixed {
        $self = $this;
        return $self->$m(...$args);
    }
}
echo (new Runner())->viaThis('secret', [6, 7]), "\n";
