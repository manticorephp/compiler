<?php
/** @param array<int,int|string> $keys */
function numericKey(array $keys): bool {
    foreach ($keys as $key) {
        if (\is_string($key) && $key === (string) (int) $key) {
            return true;
        }
    }
    return false;
}
var_dump(numericKey(['a', '1']), numericKey(['a', 'b']), numericKey([1, 2]));
