<?php
// A VEC static-property snapshot stored into a slot that a storing
// reference (`[&$m]`) keeps a CELL for its whole lifetime.
final class Rows
{
    /** @var array<int, mixed> */
    public static array<int, mixed> $rows = [];

    public static function pick(): int
    {
        $m = self::$rows;
        $holder = [&$m];
        return count($holder) + count($m);
    }
}
Rows::$rows[] = str_repeat('r', 8) . '1';
Rows::$rows[] = str_repeat('r', 8) . '2';
Rows::$rows[] = [1, 2, 3];
for ($i = 0; $i < 3; $i++) {
    echo Rows::pick(), "\n";
}
echo count(Rows::$rows), ' ', Rows::$rows[0], ' ', Rows::$rows[1], ' ', count(Rows::$rows[2]), "\n";
