<?php
// Two different enums joined by a ternary keep the first enum's names: Pure::cases() read back as Lvl cases.
// issue: #567
enum Lvl: string { case A = 'a'; case B = 'b'; }
enum Pure { case X; }
function pick(bool $b): array { return $b ? Lvl::cases() : Pure::cases(); }
foreach (pick(true) as $c) { echo $c->name; }
foreach (pick(false) as $c) { echo $c->name; }
echo "\n";
