<?php
/**
 * One-shot extractor — reads tools/test_compile_roundtrip.sh, pulls
 * every `run_case "name" '...source...' "expected"` block out and
 * writes them to tests/aot/cases/<name>.php +
 * tests/aot/expected/<name>.out so the AOT runner can pick them up.
 *
 * Re-runnable: overwrites existing files. Use it whenever the
 * roundtrip script gains new cases.
 *
 *     php tests/aot/extract_roundtrip.php
 */

$root = realpath(__DIR__ . '/../..');
$src = file_get_contents($root . '/tools/test_compile_roundtrip.sh');
$casesDir = $root . '/tests/aot/cases';
$expDir = $root . '/tests/aot/expected';
@mkdir($casesDir, 0755, true);
@mkdir($expDir, 0755, true);

// run_case "label" \
//     'php-src multi-line' \
//     "expected"
// The shell uses single-quoted heredocs for source and double-quoted
// for expected — we treat them straight-up after stripping the
// outermost quote pair.
$pattern = '/run_case\s+"([^"]+)"\s*\\\\\s*\n\s*\'((?:[^\']|\'\\\\\'\')*)\'\s*\\\\\s*\n\s*"((?:[^"\\\\]|\\\\.)*)"/m';
preg_match_all($pattern, $src, $matches, PREG_SET_ORDER);

$wrote = 0;
foreach ($matches as $m) {
    $name = $m[1];
    $php = $m[2];
    // Restore '' → ' inside single-quoted PHP source.
    $php = str_replace("''", "'", $php);
    if (!str_starts_with(ltrim($php), '<?php')) {
        $php = "<?php\n" . $php;
    }
    if (!str_ends_with($php, "\n")) {
        $php .= "\n";
    }

    // The expected string was double-quoted in bash — interpret
    // back-slash escapes the same way bash would.
    $expected = stripcslashes($m[3]);

    file_put_contents("$casesDir/$name.php", $php);
    file_put_contents("$expDir/$name.out", $expected);
    $wrote++;
}

echo "extracted $wrote cases\n";
