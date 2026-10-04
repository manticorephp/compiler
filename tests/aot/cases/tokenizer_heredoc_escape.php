<?php
$srcs = [
    "<?php \$a = <<<EOT\n    {\$r}\\\$this->f()->{\$m}(x)\n    \\{\$z} \\\\\$q \\\nEOT;\n",
    "<?php \$a = <<<EOT\nend \\\nEOT;\n",
    "<?php \$a = \"\\\$x \\\" \$y\";\n",
    "<?php \$a = <<<'EOT'\n\\\$x {\$y}\nEOT;\n",
];
foreach ($srcs as $i => $src) {
    echo "== $i\n";
    foreach (token_get_all($src) as $t) {
        echo is_array($t) ? token_name($t[0]) . ' ' . json_encode($t[1]) . ' ' . $t[2] : "'$t'", "\n";
    }
}
