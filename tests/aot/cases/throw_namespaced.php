<?php
function check(int $n): void {
    if ($n < 0) throw new \RuntimeException("neg");
}
try { check(-1); } catch (\Throwable $e) { echo $e->getMessage(); }
