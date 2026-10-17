<?php
// RFC 7677 section 3 SCRAM-SHA-256 test vector, against the pgsql driver's pure scram().
[$final, $sig] = __McPdoPgWire::scram('pencil', 'rOprNGfwEbeRWgbNEkqO',
    'r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096', 'user');
echo $final, "\n", $sig, "\n";
echo $final === 'c=biws,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,p=dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ=' ? "client ok\n" : "client BAD\n";
echo $sig === '6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=' ? "server ok\n" : "server BAD\n";
try {
    __McPdoPgWire::scram('pencil', 'rOprNGfwEbeRWgbNEkqO', 'r=OTHERNONCE,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096', 'user');
} catch (PDOException $e) {
    echo "nonce mismatch rejected\n";
}
