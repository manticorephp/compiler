<?php
interface I { public function label(): string; }
enum E implements I { case A; public function label(): int { return 1; } }
