<?php

echo serialize([]), "\n";
echo serialize([1, 2, 3]), "\n";
echo serialize(['a', 'b']), "\n";
echo serialize(['x' => 1, 'y' => 2]), "\n";
echo serialize([5 => 'five', 'k' => 'v', 6 => 'six']), "\n";
echo serialize([true, false, null, 1.5, 'str']), "\n";
echo serialize([[1, 2], [3, [4, 5]]]), "\n";
echo serialize(['outer' => ['inner' => ['deep' => 'v']]]), "\n";
echo serialize([-3 => 'neg']), "\n";
echo serialize(['0' => 'numeric-string-key']), "\n";
