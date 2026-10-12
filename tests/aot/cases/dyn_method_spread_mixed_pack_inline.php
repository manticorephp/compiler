<?php
// dynamic method call spreading a mixed pack into a private method segfaults
class Runner {
    private function secret(int $a, int $b): int { return $a * $b; }
    public function viaThis(string $m, mixed $args): mixed {
        $self = $this;
        return $self->$m(...$args);
    }
}
echo (new Runner())->viaThis('secret', [6, 7]), "\n";
