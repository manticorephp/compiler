<?php
// A `@var array<string, int[]>|null` property read back through an `@return array<string, int[]>` method yields boxed cells as ints.
final class H {
    /** @var array<string, int[]>|null */
    private ?array $ix = null;
    /** @return array<string, int[]> */
    private function idx(): array {
        if ($this->ix === null) { $i = []; foreach (['A' => 5, 'B' => 7] as $o => $id) { $i['k'][] = $id; } $this->ix = $i; }
        return $this->ix;
    }
    /** @param int[] $ids */
    private function show(array $ids): string { $s = ''; foreach ($ids as $id) { $s .= (string)$id . ','; } return $s; }
    public function get(string $m): string { $ids = $this->idx()[$m] ?? []; return $this->show($ids); }
}
echo (new H())->get('k'), "\n";
