<?php

// A fluent method returns `static`, so the receiver of the next link in the
// chain is the same class as its own receiver. Lowering has to know that: it is
// what resolves the callee's parameter list, and therefore what fills the
// OMITTED trailing arguments from their defaults. Without it the second and
// later links got no defaults at all and a `mixed $default = null` arrived as
// raw 0 — which, read through a union containing float, printed as 0.0.

class Definition
{
    // Same method NAME as the fluent one below, different return type: any
    // name-wide answer to "is addOption fluent?" is the wrong answer.
    public function addOption(string $name): void
    {
        echo "definition sink: ", $name, "\n";
    }
}

class Base
{
    public function addArgument(string $n, ?int $mode = null, string $d = '', mixed $default = null): static
    {
        echo "arg ", $n, " default=", var_export($default, true), "\n";
        return $this;
    }

    public function addOption(string $n, string|array|null $sc = null, ?int $mode = null, string $d = '', mixed $default = null, array|\Closure $sv = []): static
    {
        echo "opt ", $n, " default=", var_export($default, true), " sv=", var_export($sv, true), "\n";
        return $this;
    }
}

class Child extends Base
{
    public function configure(): void
    {
        $this
            ->addArgument('name', 4, 'Who to greet', 'world')
            ->addOption('yell', 'y', 1, 'Shout the greeting')
            ->addOption('times', 't', 2, 'Repeat count', '1')
            ->addOption('third', 'z', 1, 'Third');
    }
}

(new Child())->configure();

// The same chain rooted at a variable rather than `$this`.
$b = new Base();
$b->addOption('root-a', 'a', 1, 'desc')->addOption('root-b', 'b', 1, 'desc');

// And the unrelated same-named method still resolves to its own class.
(new Definition())->addOption('untouched');
