<?php
// `$t->key ?? T::cell()` on a readonly nullable self-typed property: the
// default must be taken when the property is null, inside a ternary too.
final class T
{
    private static ?T $cellT = null;
    public function __construct(
        public readonly string $kind,
        public readonly ?T $element = null,
        public readonly ?T $key = null,
    ) {}
    public static function cell(): T { return self::$cellT ??= new T('cell'); }
    public function isArray(): bool { return $this->kind === 'array'; }
}
function keyOf(T $at): T
{
    $kt = $at->isArray() ? ($at->key ?? T::cell()) : T::cell();
    return $kt;
}
function elemOf(T $at): T
{
    $vt = $at->element ?? new T('unknown');
    return $vt;
}
$a = new T('array', new T('int'), null);
$b = new T('array', null, new T('string'));
$c = new T('int');
foreach ([$a, $b, $c] as $t) { echo keyOf($t)->kind, ' ', elemOf($t)->kind, "\n"; }
// Two hops: `$n->type->element ?? …` walks the chain inline.
final class N { public function __construct(public T $type) {} }
function show(T $t): string { return $t->kind; }
function hop2(N $n): string { return show($n->type->element ?? new T('u')); }
function hop2key(N $n): string { $at = $n->type; return ($at->isArray() ? ($at->key ?? T::cell()) : T::cell())->kind; }
echo hop2(new N(new T('x'))), ' ', hop2(new N(new T('x', new T('e')))), "\n";
echo hop2key(new N($a)), ' ', hop2key(new N($b)), "\n";
