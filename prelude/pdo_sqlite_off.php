<?php

/** Stand-in for pdo_sqlite.php when PDO is linked only as the transport of mysqli (see the driver gate in Main.php): no libsqlite3. */

/** @param array<string,mixed> $options */
function __mc_pdo_sqlite_open(string $rest, array $options): __McPdoDrv
{
    $e = new PDOException('could not find driver (pdo_sqlite is not linked: the program names mysqli, not PDO)', 0);
    $e->errorInfo = __McPdo::triple('', 0, false, '', false);
    throw $e;
}
