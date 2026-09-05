<?php
// A FRESH array temp handed to a builtin: the caller frees it, and every
// builtin that copies out of it must first co-own what it copied. The pair is
// one change — either half alone is a leak or a use-after-free.
$src = "alpha,bravo,charlie,delta";

for ($i = 0; $i < 3; $i++) {
    echo count(explode(",", $src)), "\n";
    echo implode("|", array_values(explode(",", $src))), "\n";
    echo implode("/", array_keys(explode(",", $src))), "\n";
    echo implode(",", array_reverse(explode(",", $src))), "\n";
    echo implode(",", array_slice(explode(",", $src), 1, 2)), "\n";
    echo max(explode(",", $src)), " ", min(explode(",", $src)), "\n";
}

// The same builtins over a LIVE local: the co-owning copy must not disturb it.
$live = ["k1" => "vv1", "k2" => "vv2", "k3" => "vv3"];
$k = array_keys($live);
$v = array_values($live);
echo implode(",", $k), " ", implode(",", $v), "\n";
echo max($live), " ", min($live), "\n";
foreach ($live as $key => $val) { echo $key, "=", $val, " "; }
echo "\n", count($live), "\n";

// A result kept past the point the source would have died.
$keep = array_values(explode(",", $src));
$rev = array_reverse(explode(",", $src));
echo $keep[0], $keep[3], " ", $rev[0], $rev[3], "\n";
