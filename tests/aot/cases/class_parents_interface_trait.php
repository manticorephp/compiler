<?php

// class_parents() of an interface or a trait is [], not false — symfony's
// AutowirePass unions it with class_implements() and false + array throws.

interface I {}
interface J extends I {}
trait T {}
class A implements J {}

echo json_encode(class_parents('J')), "\n";
foreach (['I', 'T', 'A'] as $n) {
    echo $n, ': ', json_encode(class_parents($n)), ' ', json_encode(class_implements($n)), "\n";
    $u = [];
    foreach (class_parents($n) + class_implements($n) as $p) { $u[] = $p; }
    echo '  ', implode(',', $u), "\n";
}
var_dump(@class_parents('NoSuch'), @class_implements('NoSuch'));
