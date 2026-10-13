<?php
foreach (['md5', 'sha1', 'sha256', 'sha512'] as $a) {
    echo $a, ' ', hash_pbkdf2($a, 'pw', 'salt', 3), ' ', hash_pbkdf2($a, 'pw', 'salt', 2, 20), ' ',
        bin2hex(hash_pbkdf2($a, 'pw', 'salt', 5, 70, true)), ' ', hash_pbkdf2($a, 'pw', 'salt', 1, 33), "\n";
    echo bin2hex(hash_hkdf($a, 'key')), ' ', bin2hex(hash_hkdf($a, 'key', 42, 'info', 'salt')), "\n";
}
var_dump(in_array('sha256', hash_hmac_algos(), true), in_array('crc32', hash_hmac_algos(), true));
foreach ([fn() => hash_hkdf('sha256', ''), fn() => hash_hkdf('sha256', 'k', -1), fn() => hash_hkdf('sha256', 'k', 9000),
    fn() => hash_hkdf('zzz', 'k'), fn() => hash_pbkdf2('sha256', 'p', 's', 0), fn() => hash_pbkdf2('sha256', 'p', 's', 1, -1),
    fn() => hash_pbkdf2('crc32', 'p', 's', 1)] as $f) {
    try { $f(); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
$c = hash_init('sha256');
hash_update($c, 'hello ');
$d = hash_copy($c);
hash_update($c, 'world');
hash_update($d, 'there');
echo hash_final($c), "\n", hash_final($d), "\n";
$f = fopen('php://memory', 'w+');
fwrite($f, 'hello world');
rewind($f);
$c = hash_init('md5');
var_dump(hash_update_stream($c, $f, 5), hash_update_stream($c, $f));
echo hash_final($c), ' ', md5('hello world'), "\n";
$p = sys_get_temp_dir() . '/hk_' . getmypid() . '.txt';
file_put_contents($p, 'abc');
$c = hash_init('sha1');
var_dump(hash_update_file($c, $p));
echo hash_final($c), ' ', sha1('abc'), "\n";
unlink($p);
$m = @mhash(MHASH_SHA256, 'abc');
echo bin2hex($m), ' ', @mhash_get_hash_name(MHASH_MD5), ' ', @mhash_get_block_size(MHASH_SHA512), ' ', @mhash_count(), "\n";
echo bin2hex(@mhash(MHASH_SHA1, 'abc', 'k')), "\n";
