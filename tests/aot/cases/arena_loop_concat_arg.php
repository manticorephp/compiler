<?php
class Ali { public function __toString(): string { return 'n'; } }
/** @param array<string,Ali|string> $aliases */
function pick(array $aliases, string $name, string $type): ?string {
    foreach ($aliases as $id => $alias) {
        if ($name === (string) $alias && str_starts_with($id, $type . ' $')) {
            return $id;
        }
    }
    return null;
}
var_dump(pick(['A $x' => 'n', 'B $y' => new Ali()], 'n', 'B'), pick(['A $x' => 'q'], 'n', 'A'));
