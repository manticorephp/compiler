<?php
// Exception throughput: throw caught in the same function, 1 frame and 5 frames up.
function d0(int $i): int { throw new RuntimeException('x'); }
function d1(int $i): int { return d0($i) + 1; }
function d2(int $i): int { return d1($i) + 1; }
function d4(int $i): int { return d2(d2($i) - 1) + 1; }
$N = 300000;
$caught = 0;
for ($i = 0; $i < $N; $i++) { try { throw new RuntimeException('x'); } catch (RuntimeException $e) { $caught++; } }
for ($i = 0; $i < $N; $i++) { try { d0($i); } catch (RuntimeException $e) { $caught++; } }
for ($i = 0; $i < $N; $i++) { try { d4($i); } catch (RuntimeException $e) { $caught++; } }
echo $caught, "\n";
