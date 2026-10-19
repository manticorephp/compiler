<?php
// Hook helper: FLUSH PRIVILEGES as root over PDO_MY_DSN, which empties the caching_sha2 auth cache so the next login of u/pw is a FULL authentication.
// No docker needed (the toolchain container of CI has none). Exit 3 = could not: run.sh then SKIPs the case.
$dsn = (string) getenv('PDO_MY_DSN');
if (!str_contains($dsn, 'port=33060')) { fwrite(STDERR, "flush_privileges: PDO_MY_DSN does not point at mc-my (:33060)\n"); exit(3); }
try {
    (new PDO($dsn, 'root', 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('FLUSH PRIVILEGES');
} catch (Throwable $e) {
    fwrite(STDERR, 'flush_privileges: ' . $e->getMessage() . "\n"); exit(3);
}
