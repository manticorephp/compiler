<?php

final class A {}
final class B {}

/** @param (A|null)[] $xs */
function nullableElems(array $xs): void {}

/** @param (A|B)[] $xs */
function unionElems(array $xs): void {}

/** @param ?A[] $xs */
function nullableSugar(?array $xs): void {}

/** @param A[][] $xs */
function nested(array $xs): void {}

/** @param (A)[] $xs */
function parenthesized(array $xs): void {}

/** @param array<(A|B)> $xs */
function genericParen(array $xs): void {}

/** @param list<(A|B)> $xs */
function listParen(array $xs): void {}

/** @param string|int[] $xs */
function arrayArm(string|array $xs): void {}

/** @param non-empty-array<int, A> $xs */
function nonEmpty(array $xs): void {}

/** @param non-empty-list{A, B} $xs */
function nonEmptyShape(array $xs): void {}

/** @return (A|null)[] */
function nullableReturn(): array { return []; }

/** @param (A|B) $xs */
function noElement(array $xs): void {}      // warn: a group is not an array

/** @param array|null $xs */
function bareDoc(?array $xs): void {}       // warn

final class Holder
{
    /** @var (A|B)[] */
    public array $items = [];

    /** @var array<string, (A|null)[]> */
    public array $groups = [];
}
