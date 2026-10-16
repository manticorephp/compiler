<?php
foreach (['xxh128', 'crc32b', 'sha256'] as $algo) {
    $c = hash_init($algo);
    hash_update($c, 'manti');
    hash_update($c, 'core');
    echo $algo, ' ', hash_final($c), ' ', hash($algo, 'manticore'), "\n";
}
try {
    hash_init('nope');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
