<?php
function nxt(int $i): ?int { return $i < 3 ? $i + 1 : null; }
function w1(): string { $o = ""; $i = 0; while (null !== ($i = nxt($i))) { $o .= $i . ","; } return $o; }
function w2(): string { $o = ""; $i = 0; while (true) { $i = nxt($i); if ($i === null) break; $o .= $i . ","; } return $o; }
echo w1(), " | ", w2(), "\n";
function w3(): string { $o = ""; for ($i = 0; null !== $i; $i = nxt($i)) { $o .= $i . ","; } return $o; }
echo w3(), "\n";
