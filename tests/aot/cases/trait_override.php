<?php
trait Default_ {
    public function value(): int { return 1; }
}
class Box {
    use Default_;
    public function value(): int { return 42; }
}
echo (new Box())->value();
