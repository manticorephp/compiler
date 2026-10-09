<?php
// SIGSEGV when a static ??= cache holds an array whose elements are a @phpstan-type alias
// issue: #159
declare(strict_types=1);

/**
 * @phpstan-type Proto array{0: int, 1: string}|string
 */
final class Defs
{
    /** @return array<int, array{start: Proto, end: Proto}> */
    public static function all(): array
    {
        static $defs = null;

        return $defs ??= self::build();
    }

    /** @return array<int, array{start: Proto, end: Proto}> */
    private static function build(): array
    {
        return [
            1 => ['start' => '[', 'end' => ']'],
            2 => ['start' => [300, '{'], 'end' => [301, '}']],
        ];
    }

    public static function kind(string $k): int
    {
        static $kinds = null;

        if (null === $kinds) {
            $kinds = [];
            foreach (self::all() as $type => $d) {
                $kinds[\is_string($d['start']) ? $d['start'] : $d['start'][1]] = $type;
                $kinds[\is_string($d['end']) ? $d['end'] : $d['end'][1]] = $type;
            }
        }

        return $kinds[$k] ?? 0;
    }
}

foreach (['[', ']', '{', '}', 'x'] as $k) {
    echo $k, ' ', Defs::kind($k), "\n";
}
