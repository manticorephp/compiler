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
// Hostile server-first messages: each must be refused with SQLSTATE 08006, never hang or crash.
$bad = [
    'iteration above bound' => 'r=rOprNGfwEbeRWgbNEkqOxx,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=16777217',
    'iteration zero' => 'r=rOprNGfwEbeRWgbNEkqOxx,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=0',
    'iteration not digits' => 'r=rOprNGfwEbeRWgbNEkqOxx,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096abc',
    'iteration negative' => 'r=rOprNGfwEbeRWgbNEkqOxx,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=-5',
    'missing s' => 'r=rOprNGfwEbeRWgbNEkqOxx,i=4096',
    'missing i' => 'r=rOprNGfwEbeRWgbNEkqOxx,s=W22ZaJ0SNY7soEsUEjb6gQ==',
    'missing r' => 's=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096',
    'nonce not prefixed' => 'r=XXXXNGfwEbeRWgbNEkqOxx,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096',
    'nonce not extended' => 'r=rOprNGfwEbeRWgbNEkqO,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096',
    'salt not base64' => 'r=rOprNGfwEbeRWgbNEkqOxx,s=!!notbase64!!,i=4096',
];
foreach ($bad as $name => $sf) {
    try {
        __McPdoPgWire::scram('pencil', 'rOprNGfwEbeRWgbNEkqO', $sf);
        echo "$name: ACCEPTED\n";
    } catch (PDOException $e) {
        echo "$name: ", $e->errorInfo[0], ' ', str_contains($e->getMessage(), 'pencil') ? 'LEAK' : 'clean', "\n";
    }
}
