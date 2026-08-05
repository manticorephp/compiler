<?php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE b (v)');
$ins = $db->prepare('INSERT INTO b VALUES (?)');
foreach ([null, 1, -1, 2.5, 'txt', "a\x00b", true, false] as $v) { $ins->execute([$v]); }
foreach ($db->query('SELECT v, typeof(v) FROM b')->fetchAll(PDO::FETCH_NUM) as $r) {
    // bin2hex and not var_export: php renders a NUL inside a string as
    // `'a' . "\\0" . 'b'` and this build does not — a var_export gap, not a PDO
    // one, and one that would hide the round trip this case is actually about.
    echo bin2hex((string) $r[0]), " / ", $r[1], "\n";
}
// explicit PARAM_* types keep sqlite's native storage class
$db->exec('CREATE TABLE n (v)');
$t = $db->prepare('INSERT INTO n VALUES (?)');
$t->bindValue(1, 42, PDO::PARAM_INT);  $t->execute();
$t->bindValue(1, 2.5, PDO::PARAM_STR); $t->execute();
$t->bindValue(1, null, PDO::PARAM_NULL); $t->execute();
$t->bindValue(1, "bin\x00ary", PDO::PARAM_LOB); $t->execute();
foreach ($db->query('SELECT v, typeof(v), length(v) FROM n')->fetchAll(PDO::FETCH_NUM) as $r) {
    echo bin2hex((string) $r[0]), " / ", $r[1], " / ", var_export($r[2], true), "\n";
}
