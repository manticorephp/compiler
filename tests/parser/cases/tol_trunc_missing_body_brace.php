<?php
// array_map / array_filter via prelude injection (callback invoked in-module,
// not across the stdlib.o boundary which crashes). Int element/result cases.
function dbl_each(array $a): array 