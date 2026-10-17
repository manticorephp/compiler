<?php
// MySQL auth scrambles and wire helpers (no server): vectors computed with php's own sha1()/hash().
// They are self-referential by construction; the live mysql_connect / mysql_native / mysql_auth_full cases are the real check.
$gate = PDO::FETCH_ASSOC;   // a mention of PDO is what pulls the driver prelude in
$salt = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14";
foreach ([
    ['pw', 'b0df30c74af91ea34514aaab203bd4b707f86375', '71192ff0ec98fcb2cbaf7de6f3958c02e20756c534e7f907f65ae0c1125dac26'],
    ['password', 'c17d6009a5cb47e59f7483fcf05553bbbf7dd0d6', 'f7ab1c623a6e98dceab35e926290e5746a3141116115f4dd8ccca994393eccdd'],
    ['', '', ''],
] as [$pw, $nat, $sha2]) {
    echo json_encode($pw), ' native ', bin2hex(__McPdoMyWire::nativeAuth($pw, $salt)) === $nat ? 'ok' : 'BAD',
        ' sha2 ', bin2hex(__McPdoMyWire::sha2Auth($pw, $salt)) === $sha2 ? 'ok' : 'BAD', "\n";
}
// full-authentication payload: (password NUL) xor scramble, before RSA-OAEP
echo bin2hex(__McPdoMyWire::rsaPlain('pw', $salt)), "\n";
// length-encoded integers
foreach ([[0, "\x00"], [250, "\xfa"], [251, "\xfc\xfb\x00"], [65535, "\xfc\xff\xff"], [65536, "\xfd\x00\x00\x01"],
          [16777215, "\xfd\xff\xff\xff"], [16777216, "\xfe\x00\x00\x00\x01\x00\x00\x00\x00"]] as [$v, $enc]) {
    $w = __McPdoMyWire::lenencInt($v);
    $r = __McPdoMyWire::lenencAt($enc, 0);
    echo $v, ' ', $w === $enc ? 'enc ok' : 'enc BAD', ' ', $r === $v && __McPdoMyWire::lenencSize($enc, 0) === strlen($enc) ? 'dec ok' : 'dec BAD', "\n";
}
echo __McPdoMyWire::lenencAt("\xfb", 0), ' ', __McPdoMyWire::lenencAt("\xfc\x01", 0), ' ', __McPdoMyWire::lenencAt("\xff", 0),
    ' ', __McPdoMyWire::lenencAt("\xfe\xff\xff\xff\xff\xff\xff\xff\xff", 0), "\n";
echo __McPdoMyWire::le32("\x78\x56\x34\x12", 0), ' ', bin2hex(__McPdoMyWire::putLe(0x12345678, 4)), ' ', __McPdoMyWire::le24("\x01\x02\x03", 0), "\n";
echo __McPdoMyWire::beToDec("\xff\xff\xff\xff\xff\xff\xff\xff"), ' ', __McPdoMyWire::beToDec("\x01\x00"), ' ', __McPdoMyWire::beToDec("\x00"), "\n";
