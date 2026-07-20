<?php

// parse_str / http_build_query — the query-string half of the web/API stdlib.

// ── parse_str ──────────────────────────────────────────────────────────
parse_str('a=1&b=hello', $flat);
var_dump($flat);

parse_str('name=John+Doe&city=New%20York', $enc);
var_dump($enc);

parse_str('a[b]=x&a[c]=y', $nested);
var_dump($nested);

parse_str('list[]=p&list[]=q', $appended);
var_dump($appended);

parse_str('deep[a][b]=z', $deep);
var_dump($deep);

parse_str('novalue&k=v', $bare);
var_dump($bare);

parse_str('', $empty);
var_dump($empty);

// ── http_build_query ───────────────────────────────────────────────────
echo http_build_query(['a' => 1, 'b' => 'hello']), "\n";
echo http_build_query(['name' => 'John Doe', 'city' => 'New York']), "\n";
echo http_build_query(['a' => ['b' => 'x', 'c' => 'y']]), "\n";
echo http_build_query(['t' => true, 'f' => false, 'n' => null]), "\n";
echo http_build_query(['x', 'y']), "\n";
echo http_build_query([5 => 'v', 'k' => 'w']), "\n";
echo http_build_query([5 => 'v', 'k' => 'w'], 'p_'), "\n";
echo http_build_query(['a' => 1, 'b' => 2], '', ';'), "\n";
echo http_build_query(['f' => 1.5, 'i' => -7]), "\n";
echo http_build_query([]), "\n";

// ── round trip ─────────────────────────────────────────────────────────
$q = http_build_query(['user' => 'a b', 'tags' => ['x', 'y']]);
echo $q, "\n";
parse_str($q, $back);
var_dump($back);
