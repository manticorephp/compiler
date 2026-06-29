# Cli

Minimal clap-flavoured CLI router. Two classes, fluent builder, dispatch on `argv[1]`.

## Public surface

- `Cli\Cli` — root registry. Holds program name, description, and named commands.
  - `__construct(string $name, string $description)`
  - `command(string $name, string $description): Command` — register subcommand
  - `commands(): array<string, Command>` — enumerate registered commands
  - `run(array $argv): int` — pick `argv[1]`, dispatch handler with tail args
  - `runHelp(): int` — print usage block
- `Cli\Command` — one subcommand entry.
  - `__construct(string $name, string $description)`
  - `run(\Closure $handler): self` — attach handler `fn(string[]): int`
  - `handler(): ?\Closure`

## Invariants

- No command on argv → falls through to `help`. Unknown command → diag + help, returns help exit code.
- Handler missing → return 70.
- Handler receives only argv tail (everything after the command name).
- Help output goes through `\Manticore\puts`; diagnostics through `\Manticore\dprint` (stderr).
- Commands stored in insertion order (PHP array key order) — help prints them as registered.

## Usage

```php
$cli = new \Cli\Cli('manticore', 'PHP-to-native AOT compiler (self-hosted)');
$cli->command('compile', 'Compile to native binary')
    ->run(fn (array $args): int => cmd_compile($args));
$cli->command('help', 'Show help')
    ->run(fn (array $args): int => $cli->runHelp());
exit($cli->run($argv));
```

Whole module is the routing layer only. Argument parsing per command is the handler's job (see `Manticore\parse_compile_args`).
