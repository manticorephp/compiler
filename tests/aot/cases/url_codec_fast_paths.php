<?php
foreach (["", "abc", "a-b_c.d", "a b", "a+b", "100%", "%41%42", "%4", "%zz", "a%2Bb", "é~!*'();:@&=+$,/?#[]", "k123", "ünï", "a\0b", "~tilde"] as $s) {
    echo json_encode([urlencode($s), rawurlencode($s), urldecode($s), rawurldecode($s), urldecode(urlencode($s)), rawurldecode(rawurlencode($s))]), "\n";
}
echo http_build_query([]), "|", http_build_query(["a" => 1]), "|", http_build_query(["a" => 1, "b" => "x y", "c" => null, "d" => true, "e" => false, "f" => 1.5]), "\n";
echo http_build_query(["a" => [1, 2, ["x" => "y z"]], "b" => ["k" => ["m" => 1]]]), "\n";
echo http_build_query([5 => "a", "x" => "b", 7 => "c"], "n_"), "\n";
echo http_build_query(["a" => 1, "b" => 2], "", ";"), "|", http_build_query(["a" => 1, "b" => 2], "", ""), "\n";
echo http_build_query(["a b" => "c&d", "e=f" => "g"]), "\n";
echo http_build_query(["only" => null]), "|", http_build_query(["a" => [], "b" => 1]), "\n";
parse_str("a=1&b=x+y&c%5B0%5D=p&c%5B1%5D=q&d[k]=v&e&=empty&f=%41%42&g=a%2Bb", $o);
echo json_encode($o), "\n";
parse_str("", $o); echo json_encode($o), "\n";
parse_str("x=1&x=2&y[]=1&y[]=2", $o); echo json_encode($o), "\n";
$big = [];
for ($i = 0; $i < 200; $i++) { $big["k$i"] = $i; }
$q = http_build_query($big);
echo strlen($q), " ", md5($q), "\n";
parse_str($q, $back); echo count($back), " ", $back["k199"], "\n";
echo json_encode(array_fill(0, 0, 1)), json_encode(array_fill(0, 3, "x")), json_encode(array_fill(5, 2, null)), json_encode(array_fill(-3, 2, 1.5)), json_encode(array_fill(0, 2, [1])), "\n";
echo json_encode(array_merge_recursive(["a" => 1, "b" => "x", "c" => [1], 5 => "n"], ["a" => 2, "b" => ["y"], "c" => 3, 5 => "m", "d" => 4])), "\n";
echo json_encode(array_merge_recursive(["a" => "p"], ["a" => "q"], ["a" => "r"])), "\n";
echo json_encode(array_merge_recursive(["a" => null], ["a" => false])), json_encode(array_merge_recursive([], ["z" => 1])), "\n";
echo json_encode(array_merge_recursive(["a" => ["b" => 1]], ["a" => ["b" => 2]])), "\n";
