<?php

namespace P {
    class Process
    {
        public function __construct(public array $cmd, public ?string $cwd = null) {}

        public static function fromShellCommandline(string $command, ?string $cwd = null): static
        {
            $p = new static([], $cwd);
            $p->cmd = [$command];
            return $p;
        }
    }

    // Overrides without forwarding: Process's body never runs with static = PhpProcess,
    // so its `new static([], …)` is never checked against this constructor.
    class PhpProcess extends Process
    {
        public function __construct(string $script, ?string $cwd = null) { parent::__construct(['php', $script], $cwd); }

        public static function fromShellCommandline(string $command, ?string $cwd = null): static
        {
            throw new \LogicException('not for PhpProcess');
        }
    }

    // Overrides AND forwards: parent:: keeps static = Traced.
    class Traced extends Process
    {
        public static function fromShellCommandline(string $command, ?string $cwd = null): static
        {
            $p = parent::fromShellCommandline('trace ' . $command, $cwd);
            return $p;
        }
    }
}

namespace {
    var_dump(P\Process::fromShellCommandline('ls')->cmd);
    var_dump((new P\PhpProcess('x.php'))->cmd);
    $t = P\Traced::fromShellCommandline('ls', '/tmp');
    var_dump(get_class($t), $t->cmd, $t->cwd);
    try { P\PhpProcess::fromShellCommandline('ls'); } catch (LogicException $e) { echo $e->getMessage(), "\n"; }
}
