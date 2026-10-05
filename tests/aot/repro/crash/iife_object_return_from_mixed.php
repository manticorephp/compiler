<?php
// An immediately-invoked closure `(function (string $s): P { return unserialize($s); })(serialize(new P()))` SIGSEGVs
// issue: #96
final class P { public int $n = 7; }
$h = (function (string $s): P { return unserialize($s); })(serialize(new P()));
echo $h->n, "\n";
