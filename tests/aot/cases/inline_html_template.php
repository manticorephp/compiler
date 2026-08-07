<!DOCTYPE html>
<p>before any php at all</p>
<?php

// Inline HTML, php's short echo tag, and the ALTERNATIVE statement syntax —
// the three things every php TEMPLATE is made of, and none of them existed
// here before: the lexer treated every byte of every file as php, so a file of
// plain text did not compile and neither did symfony/error-handler's
// Resources/views/*.php. That was tier 4's second blocker.
//
// php starts each file in HTML mode and only enters php at an open tag, so
// literal output is the DEFAULT and lowers to an echo of a string constant.
// A close tag returns to HTML mode and swallows exactly one following newline.

$name = 'world';
$items = ['a' => 1, 'b' => 2];
?>
<p>hello <?= $name ?>, <?= strtoupper($name) ?></p>
<p>list: <?= count($items) ?> item(s)</p>

<?php foreach ($items as $k => $v): ?>
<li><?= $k ?>=<?= $v ?></li>
<?php endforeach; ?>

<?php $n = 3; if ($n > 2): ?>
<b>big</b>
<?php elseif ($n > 1): ?>
<b>medium</b>
<?php else: ?>
<b>small</b>
<?php endif; ?>

<?php for ($i = 0; $i < 2; $i++): ?>
<span>f<?= $i ?></span>
<?php endfor; ?>

<?php $j = 0; while ($j < 2): ?>
<span>w<?= $j ?></span>
<?php $j++; endwhile; ?>

<?php
// the brace forms must keep working alongside the alternative ones
foreach ($items as $k => $v) {
    echo "brace $k=$v\n";
}
if ($n > 2) { echo "brace big\n"; }

// a close tag ends a statement the way a `;` does
echo "tail\n";
?>
<p>after the last php block</p>
