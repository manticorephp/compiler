<?php
// strpos/stripos with an offset past the end must throw ValueError, not return false.
try { var_dump(strpos('abc', 'a', 4)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { var_dump(stripos('abc', 'a', -4)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
foreach (['strpos', 'stripos', 'strrpos', 'strripos'] as $fn) {
    foreach ([4, -4, 3, -3, 0] as $o) {
        try { var_dump($fn('abc', 'c', $o)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
    }
}
function dyn(string $h, int $o) {
    try { var_dump(strpos($h, 'b', $o), stripos($h, 'B', $o), strrpos($h, 'b', $o), strripos($h, 'B', $o)); }
    catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
dyn('abc', 1); dyn('abc', 9); dyn('abc', -9); dyn('', 0); dyn('', 1);
$f = 'strpos';
try { var_dump($f('abc', 'a', 7)); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
