<?php
// A user label inside a finally body: every inlined copy (return, break, continue,
// nested finally) gets its own block, and a goto stays inside its copy.
function f(): int { try { return 1; } finally { goto a; echo "skipped\n"; a: echo "f fin\n"; } }
echo f(), "\n";
function g(): int {
    $n = 0;
    for ($i = 0; $i < 4; $i++) {
        try {
            if ($i === 1) { continue; }
            if ($i === 3) { break; }
            $n += 10;
        } finally {
            $j = 0;
            loop:
            $j++;
            if ($j < 2) { goto loop; }
            $n += $j;
        }
    }
    return $n;
}
echo g(), "\n";
function h(): string {
    $s = '';
    try {
        try { return 'r'; } finally { goto in1; in1: $s .= 'i'; echo "inner $s\n"; }
    } finally { goto out1; out1: $s .= 'o'; echo "outer $s\n"; }
}
echo h(), "\n";
