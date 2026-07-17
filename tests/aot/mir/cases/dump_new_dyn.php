<?php
class Red { public function name(): string { return "red"; } }
class Blue { public function name(): string { return "blue"; } }
function make(string $cls): object {
    return new $cls();
}
echo make(Red::class)->name();
