<?php
// is_numeric over a CELL masked the string scan's RESULT by the tag but ran the
// scan anyway — a NULL cell has payload 0, so it dereferenced address 0.
// symfony's Command::run ends with is_numeric($statusCode) over a mixed slot.
function probe(mixed $v): string
{
    return \is_numeric($v) ? 'num' : 'not';
}
$vals = [null, 'abc', '42', '3.5', ' 7 ', '', 12, 4.5, true, false, [], ['1']];
foreach ($vals as $v) { echo probe($v), "\n"; }

function statusOf(mixed $code): int
{
    return \is_numeric($code) ? (int) $code : 0;
}
echo statusOf(null), "\n";
echo statusOf('7'), "\n";
echo statusOf(3), "\n";
echo statusOf('x'), "\n";
