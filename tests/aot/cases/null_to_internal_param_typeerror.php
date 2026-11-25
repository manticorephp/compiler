<?php
// null into a non-nullable scalar parameter of an internal function throws php 9's TypeError
// (#751): stdlib bodies, codegen builtins, a literal null, a ?string local, a mixed local.
function probe(?string $s, mixed $n): void
{
    try { echo strtoupper($s), "|\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    try { echo trim($s), "|\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    try { echo str_repeat("a", $n), "|\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    try { echo round($n), "|\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    try { echo str_pad($s, 3), "|\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    try { echo strlen(null), "|\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    echo $s ?? 'n', strlen($s ?? ''), "|\n";
}
probe(null, null);
probe("ab", 2);
