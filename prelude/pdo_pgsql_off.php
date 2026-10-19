<?php

/**
 * Stand-in for pdo_pgsql.php in a PDO program that never names the pgsql driver
 * (see the driver gate in Main.php): pdo.php's registry arm and its pg*
 * extras still resolve, and the driver answers like an unknown one.
 */
final class __McPdoPgNotify
{
    public string $channel = '';
    public int $pid = 0;
    public string $payload = '';
}

final class __McPdoPg
{
    public function pid(): int { return 0; }
    public function notify(int $ms): ?__McPdoPgNotify { return null; }
    public function copyFromRows(string $table, iterable $rows, string $sep, string $nullAs, ?string $fields): void {}
    public function copyFromFile(string $table, string $file, string $sep, string $nullAs, ?string $fields): void {}
    /** @return array<int,string> */
    public function copyToRows(string $table, string $sep, string $nullAs, ?string $fields): array { return []; }
    public function copyToFile(string $table, string $file, string $sep, string $nullAs, ?string $fields): void {}
}

/** @param array<string,mixed> $options */
function __mc_pdo_pgsql_open(string $rest, array $options, ?string $user, ?string $pw): __McPdoDrv
{
    $e = new PDOException('could not find driver (pdo_pgsql is not linked: every `new PDO(..)` in the program spells another driver in a literal DSN; build the DSN from a variable or spell "pgsql:" in a literal one)', 0);
    $e->errorInfo = __McPdo::triple('', 0, false, '', false);
    throw $e;
}
