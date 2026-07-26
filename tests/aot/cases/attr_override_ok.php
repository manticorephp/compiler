<?php

// Valid #[\Override] uses compile clean: a parent method, a grandparent method,
// an interface method, a parent property, a promoted ctor property — and
// #[\DelayedTargetValidation] suppresses an otherwise-fatal target mismatch.

interface Renderer
{
    public function render(): string;
}

class GrandParent_
{
    public function deep(): string { return 'deep'; }
}

class Base extends GrandParent_
{
    public string $kept = 'k';

    public function __construct(public int $promoted = 3) {}

    public function shallow(): string { return 'shallow'; }
}

final class Leaf extends Base implements Renderer
{
    #[\Override]
    public string $kept = 'leaf-k';

    #[\Override]
    public function shallow(): string { return 'leaf-shallow'; }

    #[\Override]
    public function deep(): string { return 'leaf-deep'; }

    #[\Override]
    public function render(): string { return 'leaf-render'; }
}

$l = new Leaf(9);
echo $l->shallow(), "\n";
echo $l->deep(), "\n";
echo $l->render(), "\n";
echo $l->kept, "\n";
echo $l->promoted, "\n";

#[\Override]
#[\DelayedTargetValidation]
class Delayed {}

echo get_class(new Delayed()), "\n";
