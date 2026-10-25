<?php
// A nested array returned from a private method and merged with += into a literal comes out as empty arrays.
// issue: #297
class Arg { function __construct(private string $n, private mixed $d = null) {}
  function getName(): string { return $this->n; } }
class Def { function __construct(private array $a) {} function getArguments(): array { return $this->a; } }
class Cmd { function __construct(private Def $d) {} function getDefinition(): Def { return $this->d; } function getName(): string { return 'g'; } function getDescription(): string { return 'dd'; }
  function getProcessedHelp(): string { return 'h'; } }
class D {
  private function argData(Arg $a): array {
    return ['name' => $a->getName(), 'default' => 1];
  }
  private function defData(Def $definition): array {
    $in = [];
    foreach ($definition->getArguments() as $name => $arg) { $in[$name] = $this->argData($arg); }
    return ['arguments' => $in];
  }
  function cmd(Cmd $command): array {
    $data = ['name' => $command->getName(), 'description' => $command->getDescription()];
    $data += ['usage' => ['s'], 'help' => $command->getProcessedHelp(), 'definition' => $this->defData($command->getDefinition())];
    return $data;
  }
}
echo json_encode((new D)->cmd(new Cmd(new Def(['name' => new Arg('name', 'world')])))), "\n";
