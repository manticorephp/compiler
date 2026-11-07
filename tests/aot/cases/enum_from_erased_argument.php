<?php
// Enum::from / tryFrom coerce an erased argument to the backing type and evaluate it once.
enum S: string { case A = 'a'; case B = 'b'; case N = '5'; }
enum I: int { case One = 1; case Two = 2; }
function arr(): array { return [['t' => 'b', 'i' => 2, 's' => '1', 'n' => 5], []]; }
foreach (arr() as $d) {
    $t = S::tryFrom($d['t'] ?? 'a'); echo $t?->name ?? 'null', ' ';
    echo S::from($d['t'] ?? 'a')->name, ' ';
    echo I::tryFrom($d['i'] ?? 1)?->name ?? 'null', ' ';
    echo I::from($d['i'] ?? 1)->name, ' ';
    echo I::tryFrom($d['s'] ?? 2)?->name ?? 'null', ' ';
    echo S::tryFrom($d['n'] ?? 'zz')?->name ?? 'null', "\n";
}
function m(mixed $v): ?S { return S::tryFrom($v); }
echo m('a')->name, m(5)->name, var_export(m('q'), true), "\n";
function next_(): string { echo "[eval]"; return 'nope'; }
try { S::from(next_()); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
function nx(): mixed { echo "[eval]"; return 9; }
try { I::from(nx()); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { S::from(nx()); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
