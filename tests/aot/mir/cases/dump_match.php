<?php
function label(int $x): string {
    return match ($x) {
        1, 2 => "low",
        3 => "mid",
        default => "high",
    };
}
echo label(2), label(9);
