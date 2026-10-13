<?php
$salts = ['$2y$05$abcdefghijklmnopqrstuu', '$2b$06$.rCVZVOThsIa97pEDOxvGu', '$2a$04$0123456789012345678901',
    '$2x$05$abcdefghijklmnopqrstuO', '$5$saltsalt$', '$5$rounds=1000$abc$', '$6$saltsalt$',
    '$6$rounds=2000$0123456789abcdefXYZ$', '$1$abcdefgh$', '$1$xy$', '$2y$03$abcdefghijklmnopqrstuu', '$6$rounds=1$z$'];
foreach ($salts as $s) {
    foreach (['pw', '', "ünï\xff", str_repeat('longer password!', 5)] as $p) {
        echo $s, ' ', strlen($p), ' ', crypt($p, $s), "\n";
    }
}
