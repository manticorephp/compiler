<?php

final class Report
{
    /**
     * @param array{
     *     type: int,
     *     path: string,
     *     tags?: list<string>,
     * } $row
     *
     * @return array{
     *     type: int,
     *     label: string,
     * }
     */
    public static function label(array $row): array
    {
        return ['type' => $row['type'], 'label' => $row['path'] . ':' . implode(',', $row['tags'] ?? [])];
    }
}

$out = Report::label(['type' => 3, 'path' => 'a.php', 'tags' => ['x', 'y']]);
var_dump($out['type'], $out['label']);
$out = Report::label(['type' => 4, 'path' => 'b.php']);
echo $out['label'], "\n";
