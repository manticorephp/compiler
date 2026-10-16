# Cli

Minimal clap-flavoured CLI framework. A command is a class that declares its
options; `Cli` parses, generates help, and dispatches on `argv[1]`.

## Public surface

- `Cli\Cli` — root registry.
  - `__construct(string $name, string $description)`
  - `add(Command $cmd): void` — register a command (insertion order = help order)
  - `run(array $argv): int` — dispatch `argv[1]` with the tail args
  - `runHelp(): int` — print the generated root help
- `Cli\Command` — abstract base, one subclass per command (`src/Cli/Commands/*Command.php`).
  - `name(): string`, `summary(): string` (one line, shown in root help)
  - `usage(): string` — text after `Usage: <prog> <name> ` (default `[options]`)
  - `options(): Option[]` — (default none) declared options; drive parsing and `--help`
  - `run(ParsedArgs $p): int` — the command body
- `Cli\Option(name, kind, help, placeholder = '')` — `kind` is `ArgParse::FLAG` or `ArgParse::VALUE`;
  one-letter names render `-x`, longer `--name`.
- `Cli\ArgParse::parse(array $args, array $spec): ParsedArgs` — shared parser. Accepts every
  value form (`-o out`, `-O2`, `--memory=rc`, `--memory rc`); positionals in any position.
- `Cli\ParsedArgs` — `flag(string)`, `value(string, string $default)`, `has(string)`,
  `$positional`, `$error` (null on success).

## Invariants

- Parse error → `<command>: <error>` on stderr, rc 64. An undeclared option is a parse error
  (so `version --anything` exits 64).
- Help is generated from `summary()` / `usage()` / `options()`; nothing is hand-written.
- `--help` / `-h` are detected AFTER parsing, on the parsed result: `compile -o --help` makes
  `--help` the value of `-o`, not a help request.
- Root: no command, `help`, `--help`, `-h` print root help (rc 0). `help` is a built-in word,
  listed as `help  Show this help text`. `--version` is an alias of `version`.
- Unknown command → `unknown command: <x>` on stderr, then root help.
- Help goes through `\Manticore\puts`; diagnostics through `\Manticore\dprint` (stderr).
- A command receives only its own parsed options; shared sets live in
  `Cli\Commands\CompileOptions::shared()` (`compile`, `build`, `dump-*`).

## Usage

```php
final class VersionCommand extends \Cli\Command
{
    public function name(): string { return 'version'; }
    public function summary(): string { return 'Print compiler version'; }
    public function usage(): string { return ''; }
    public function run(\Cli\ParsedArgs $p): int { \Manticore\puts('manticore ' . \Manticore\Version::STRING); return 0; }
}

$cli = new \Cli\Cli('manticore', 'PHP-to-native AOT compiler (self-hosted)');
$cli->add(new VersionCommand());
exit($cli->run($argv));
```
