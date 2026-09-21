<?php
// A `foreach … as &$v` over a superglobal promotes each element to a REF box;
// the whole reset that follows runs the DECL-flavour walker over the array,
// which must release a REF-tagged element as a box, not as a string.
for ($round = 0; $round < 3; $round++) {
    $_POST = ['a' => 'x' . $round, 'b' => 'y' . $round];
    foreach ($_POST as &$v) { $v .= '!'; }
    unset($v);
    print_r($_POST);
    $_POST = ['c' => 'z' . $round];
    print_r($_POST);
}
echo "done\n";
