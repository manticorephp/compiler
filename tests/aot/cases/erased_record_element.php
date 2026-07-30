<?php
// An element read out of an ERASED array comes back NaN-boxed, and every
// consumer has to say so at runtime rather than trust a static claim.
//
// The trigger is `$rec['commands'] = …`: one STRING-keyed store into a local
// whose type is unknown used to retype the WHOLE local to vec[<that value>], so
// the sibling `$rec['id']` — a string — became statically an ARRAY. From there
// it rendered as its raw carrier word, was hashed as an int key, and was handed
// to string params and printf as a pointer.
//
// This is symfony's TextDescriptor::describeApplication walking
// ApplicationDescription::getNamespaces(): array.

class Cmd
{
    public function __construct(private string $name, private string $desc) {}
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->desc; }
}

class Registry
{
    private array $records = [];
    private array $commands = [];

    public function build(): void
    {
        $this->commands['help'] = new Cmd('help', 'Display help');
        $this->commands['list'] = new Cmd('list', 'List commands');
        $this->commands['config:set'] = new Cmd('config:set', 'Set a key');
        $this->records['_global'] = ['id' => '_global', 'commands' => ['help', 'list', 'gone']];
        $this->records['config'] = ['id' => 'config', 'commands' => ['config:set']];
    }

    public function getRecords(): array { return $this->records; }
    public function getCommands(): array { return $this->commands; }
}

function width(?string $s): int { return strlen($s ?? ''); }

$reg = new Registry();
$reg->build();
$commands = $reg->getCommands();

foreach ($reg->getRecords() as $rec) {
    // The poisoning store: a string key on an erased base.
    $rec['commands'] = array_filter($rec['commands'], static fn ($n) => isset($commands[$n]));

    if (!$rec['commands']) {
        continue;
    }

    // Concat, echo and a comparison, all over the erased sibling element.
    if ('_global' !== $rec['id']) {
        echo ' <' . $rec['id'] . ">\n";
    } else {
        echo ' <global:', $rec['id'], ">\n";
    }

    foreach ($rec['commands'] as $name) {
        // The element as an array KEY, then through a string param, a string
        // builtin and printf's %s.
        $cmd = $commands[$name];
        echo sprintf('  %s%s%s', $name, str_repeat(' ', 12 - width($name)), $cmd->getDescription()), "\n";
        echo '    len=', strlen($name), ' upper=', strtoupper($name),
             ' same=', $name === $cmd->getName() ? 'y' : 'n', "\n";
    }
}
echo "done\n";
