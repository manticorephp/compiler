<?php

// A try/catch inside a GENERATOR saves the backtrace depth at try entry and
// restores it in the catch landing pad. That snapshot used to live in an inline
// alloca, emitted in whatever block the `try` occupied -- and the resume switch
// re-enters past that block, so the alloca dominated neither the write nor the
// read. `Instruction does not dominate all uses`, out of symfony/cache's
// doDeleteYieldTags, whose try sits inside an `if` inside a loop.
//
// The shape needs the try NESTED in a branch and a loop, a yield inside it so a
// resume point lands past the try's own block, and a real throw so the catch
// path is live.

function boom(int $i): string
{
    if ($i === 2) { throw new RuntimeException('boom at ' . $i); }
    return 'ok' . $i;
}

function gen(bool $flag, int $n): Generator
{
    $log = '';
    if ($flag) {
        for ($i = 0; $i < $n; $i = $i + 1) {
            try {
                $log = $log . boom($i) . ';';
                yield 'step' . $i;
            } catch (RuntimeException $e) {
                $log = $log . 'caught(' . $e->getMessage() . ');';
                yield 'recovered' . $i;
            } finally {
                $log = $log . 'fin' . $i . ';';
            }
        }
    } else {
        yield 'off';
    }
    yield 'log=' . $log;
}

foreach (gen(true, 4) as $v) { echo $v, "\n"; }
foreach (gen(false, 4) as $v) { echo $v, "\n"; }

// A trace taken after the generator ran must not have grown.
function depth(): int { return count(debug_backtrace()); }
echo 'depth=', depth(), "\n";
