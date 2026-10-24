<?php
// ownflow verifier rejects a [[]]-seeded accumulator rebound from a cell-element array (Laravel Arr::crossJoin)
class Arr {
    /** @return array<int, array<array-key, mixed>> */
    public static function crossJoin(...$arrays)
    {
        $results = [[]];
        foreach ($arrays as $index => $array) {
            $append = [];
            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;
                    $append[] = $product;
                }
            }
            $results = $append;
        }
        return $results;
    }
}
echo json_encode(Arr::crossJoin([1, 2], ['a', 'b'])), "\n";
