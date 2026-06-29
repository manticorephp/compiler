<?php
class Box {
    public function __construct(public readonly ?Box $inner = null) {}
    public function label(): string {
        $inner = $this->inner?->label();
        if ($inner === null) { return 'leaf'; }
        return 'box[' . $inner . ']';
    }
}
echo (new Box(new Box(new Box())))->label(), "\n";
echo (new Box())->label(), "\n";
