<?php
// foreach rewriting the elements of a `@param array` parameter crashes in __mir_array_cow when reached through an untyped wrapper
// issue: #493
/** @param array $data */
function fmt($data) {
    foreach ($data as $field => $value) {
        $data[$field] = ['value' => $value];
    }
    return $data;
}
function pf($data) {
    return fmt($data);
}
echo json_encode(pf(['name' => 'alpha'])), "\n";
