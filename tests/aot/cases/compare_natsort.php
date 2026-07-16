<?php
// Natural order: a run of digits compares as a NUMBER, so "img12" sorts after
// "img2" where a byte-wise strcmp puts it first. A LEADING ZERO switches the
// run to fraction semantics ("0.05" < "0.5").
$pairs = [
    ["img12","img2"], ["img2","img12"], ["img10","img2"], ["img1","img1"],
    ["a","b"], ["","a"], ["",""], ["a",""],
    ["1","2"], ["10","9"], ["007","7"], ["0.05","0.5"], ["0.5","0.05"],
    ["x2-g8","x2-y7"], ["x2-y7","x2-y08"], ["x8-y8","x2-y8"],
    ["1.001","1.002"], ["1.002","1.010"], ["1.010","1.02"],
    ["fred","pic2"], ["pic 5","pic 6"], ["pic01","pic1"], ["pic2","pic02"],
    ["A1","a1"], ["a1","A1"], ["Fred","fred"],
    ["  12","12"], ["12 ","12"], ["rfc2086","rfc822"],
];
foreach ($pairs as $p) {
    echo $p[0], "|", $p[1], " => ", strnatcmp($p[0], $p[1]), " / ", strnatcasecmp($p[0], $p[1]), "\n";
}
$a = ["img12","img10","img2","img1"];
usort($a, 'strnatcmp');
print_r($a);
$b = ["IMG12","img10","IMG2","img1"];
usort($b, 'strnatcasecmp');
print_r($b);
$v = ["a"=>"img12","b"=>"img10","c"=>"img2","d"=>"img1"];
natsort($v);
print_r($v);
$w = ["a"=>"IMG12","b"=>"img10","c"=>"IMG2","d"=>"img1"];
natcasesort($w);
print_r($w);
