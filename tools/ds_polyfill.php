<?php
// Assemble the `manticorephp/ds` polyfill package: the Manticore\Ds classes
// (prelude/ds.php) over the PHP twins of the native buffer builtins
// (src/Runtime/Stdlib/Buf.php), so the same program runs under Zend.
//
//   php tools/ds_polyfill.php [out-dir]     (default: build/ds-polyfill)

$root = dirname(__DIR__);
$out = $argv[1] ?? $root . '/build/ds-polyfill';
if (!is_dir($out . '/src') && !mkdir($out . '/src', 0777, true)) {
    fwrite(STDERR, "ds_polyfill: cannot create $out/src\n");
    exit(1);
}
foreach (['src/Runtime/Stdlib/Buf.php' => 'src/Buf.php', 'prelude/ds.php' => 'src/ds.php'] as $from => $to) {
    if (!copy($root . '/' . $from, $out . '/' . $to)) {
        fwrite(STDERR, "ds_polyfill: cannot copy $from\n");
        exit(1);
    }
}
// Under the native compiler the classes are built in: the guard keeps a
// program that requires the package from declaring them twice.
$boot = "<?php\n\nif (!\\class_exists(\\Manticore\\Ds\\TypedArray::class, false)) {\n"
    . "    require __DIR__ . '/Buf.php';\n    require __DIR__ . '/ds.php';\n}\n";
file_put_contents($out . '/src/bootstrap.php', $boot);
$composer = [
    'name' => 'manticorephp/ds',
    'description' => 'Typed fixed-width arrays (Manticore\\Ds) — pure-PHP polyfill of the Manticore compiler built-ins',
    'type' => 'library',
    'license' => 'MIT',
    'require' => ['php' => '>=8.3'],
    'autoload' => ['files' => ['src/bootstrap.php']],
];
file_put_contents($out . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "ds_polyfill: wrote $out\n";
