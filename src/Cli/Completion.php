<?php

namespace Cli;

/**
 * Shell completion scripts rendered from the registry: command names from
 * {@see Cli::registry()}, options from {@see Command::options()}. Output is a
 * pure function of the registered commands (insertion order, no paths).
 */
final class Completion
{
    /** Value options whose placeholder names a file. */
    private static function isPath(Option $o): bool
    {
        return $o->placeholder === 'path';
    }

    private static function flagOf(Option $o): string
    {
        return (\strlen($o->name) === 1 ? '-' : '--') . $o->name;
    }

    /** @return string[] */
    private static function commandNames(Cli $cli): array
    {
        /** @var string[] $names */
        $names = [];
        foreach ($cli->registry() as $name => $cmd) {
            $names[] = $name;
        }
        $names[] = 'help';
        return $names;
    }

    public static function bash(Cli $cli): string
    {
        $prog = $cli->name;
        $words = \implode(' ', self::commandNames($cli)) . ' --version --help';
        $out = '# bash completion for ' . $prog . "\n";
        $out .= '_' . $prog . "_files() {\n";
        $out .= "    local IFS=\$'\\n'\n";
        $out .= "    COMPREPLY=(\$(compgen -f -- \"\$1\"))\n";
        $out .= "}\n\n";
        $out .= '_' . $prog . "() {\n";
        $out .= "    local cur prev cmd\n";
        $out .= "    COMPREPLY=()\n";
        $out .= "    cur=\"\${COMP_WORDS[COMP_CWORD]}\"\n";
        $out .= "    prev=\"\${COMP_WORDS[COMP_CWORD-1]}\"\n";
        $out .= "    if [ \"\$COMP_CWORD\" -eq 1 ]; then\n";
        $out .= "        COMPREPLY=(\$(compgen -W \"" . $words . "\" -- \"\$cur\"))\n";
        $out .= "        return 0\n";
        $out .= "    fi\n";
        $out .= "    cmd=\"\${COMP_WORDS[1]}\"\n";
        $out .= "    case \"\$cmd\" in\n";
        foreach ($cli->registry() as $name => $cmd) {
            /** @var string[] $opts */
            $opts = ['--help'];
            /** @var string[] $pathOpts */
            $pathOpts = [];
            /** @var string[] $valOpts */
            $valOpts = [];
            foreach ($cmd->options() as $o) {
                $f = self::flagOf($o);
                $opts[] = $f;
                if ($o->kind === ArgParse::VALUE) {
                    if (self::isPath($o)) {
                        $pathOpts[] = $f;
                    } else {
                        $valOpts[] = $f;
                    }
                }
            }
            $out .= '        ' . $name . ")\n";
            if (\count($pathOpts) > 0 || \count($valOpts) > 0) {
                $out .= "            case \"\$prev\" in\n";
                if (\count($pathOpts) > 0) {
                    $out .= '                ' . \implode('|', $pathOpts) . ') _' . $prog . "_files \"\$cur\"; return 0 ;;\n";
                }
                if (\count($valOpts) > 0) {
                    $out .= '                ' . \implode('|', $valOpts) . ") return 0 ;;\n";
                }
                $out .= "            esac\n";
            }
            if ($name === 'completions') {
                $out .= "            COMPREPLY=(\$(compgen -W \"bash zsh fish\" -- \"\$cur\"))\n";
                $out .= "            return 0\n";
            } else {
                $out .= "            if [[ \"\$cur\" == -* ]]; then\n";
                $out .= "                COMPREPLY=(\$(compgen -W \"" . \implode(' ', $opts) . "\" -- \"\$cur\"))\n";
                $out .= "            else\n";
                $out .= '                _' . $prog . "_files \"\$cur\"\n";
                $out .= "            fi\n";
            }
            $out .= "            ;;\n";
        }
        $out .= "    esac\n";
        $out .= "    return 0\n";
        $out .= "}\n\n";
        $out .= 'complete -o filenames -F _' . $prog . ' ' . $prog . "\n";
        return $out;
    }

    /** `'` closes the quote, emits a literal quote, reopens it. */
    private static function zshQuote(string $s): string
    {
        return \str_replace("'", "'\\''", $s);
    }

    /** _describe splits `name:description` on the first unescaped colon. */
    private static function zshDescribe(string $s): string
    {
        return self::zshQuote(\str_replace(':', '\\:', \str_replace('\\', '\\\\', $s)));
    }

    /** Inside an _arguments `[explanation]` brackets and backslashes need escaping. */
    private static function zshExplain(string $s): string
    {
        $s = \str_replace('\\', '\\\\', $s);
        $s = \str_replace('[', '\\[', $s);
        $s = \str_replace(']', '\\]', $s);
        return self::zshQuote($s);
    }

    public static function zsh(Cli $cli): string
    {
        $prog = $cli->name;
        $out = '#compdef ' . $prog . "\n\n";
        $out .= '_' . $prog . "() {\n";
        $out .= "  local -a commands\n";
        $out .= "  commands=(\n";
        foreach ($cli->registry() as $name => $cmd) {
            $out .= "    '" . $name . ':' . self::zshDescribe($cmd->summary()) . "'\n";
        }
        $out .= "    'help:Show this help text'\n";
        $out .= "  )\n";
        $out .= "  if (( CURRENT == 2 )); then\n";
        $out .= "    if [[ \$words[CURRENT] == -* ]]; then\n";
        $out .= "      compadd -- --version --help\n";
        $out .= "    else\n";
        $out .= "      _describe -t commands '" . $prog . " command' commands\n";
        $out .= "    fi\n";
        $out .= "    return\n";
        $out .= "  fi\n";
        $out .= "  case \$words[2] in\n";
        foreach ($cli->registry() as $name => $cmd) {
            $out .= '    ' . $name . ")\n";
            if ($name === 'completions') {
                $out .= "      _values 'shell' bash zsh fish\n";
                $out .= "      ;;\n";
                continue;
            }
            $out .= "      _arguments \\\n";
            $out .= "        '--help[Show this help text]' \\\n";
            foreach ($cmd->options() as $o) {
                $spec = '';
                if ($o->kind === ArgParse::VALUE) {
                    $ph = $o->placeholder !== '' ? $o->placeholder : 'value';
                    $action = self::isPath($o) ? '_files' : '';
                    $spec = (\strlen($o->name) === 1 ? '-' . $o->name . '+' : '--' . $o->name . '=')
                        . '[' . self::zshExplain($o->help) . ']:' . self::zshQuote(\str_replace(':', '\\:', $ph)) . ':' . $action;
                } else {
                    $spec = self::flagOf($o) . '[' . self::zshExplain($o->help) . ']';
                }
                $out .= "        '" . $spec . "' \\\n";
            }
            $out .= "        '*:file:_files'\n";
            $out .= "      ;;\n";
        }
        $out .= "  esac\n";
        $out .= "}\n\n";
        $out .= "if [[ \$funcstack[1] == _" . $prog . " ]]; then\n";
        $out .= '  _' . $prog . " \"\$@\"\n";
        $out .= "elif (( \$+functions[compdef] )); then\n";
        $out .= '  compdef _' . $prog . ' ' . $prog . "\n";
        $out .= "fi\n";
        return $out;
    }

    private static function fishQuote(string $s): string
    {
        return "'" . \str_replace("'", "\\'", \str_replace('\\', '\\\\', $s)) . "'";
    }

    public static function fish(Cli $cli): string
    {
        $prog = $cli->name;
        $out = '# fish completion for ' . $prog . "\n";
        $c = 'complete -c ' . $prog;
        $first = ' -n __fish_use_subcommand';
        foreach ($cli->registry() as $name => $cmd) {
            $out .= $c . $first . ' -f -a ' . $name . ' -d ' . self::fishQuote($cmd->summary()) . "\n";
        }
        $out .= $c . $first . ' -f -a help -d ' . self::fishQuote('Show this help text') . "\n";
        $out .= $c . $first . ' -l version -d ' . self::fishQuote('Print compiler version') . "\n";
        $out .= $c . $first . ' -l help -d ' . self::fishQuote('Show this help text') . "\n";
        foreach ($cli->registry() as $name => $cmd) {
            $cond = ' -n ' . self::fishQuote('__fish_seen_subcommand_from ' . $name);
            if ($name === 'completions') {
                $out .= $c . $cond . ' -f -a ' . self::fishQuote('bash zsh fish') . "\n";
            }
            $out .= $c . $cond . ' -l help -d ' . self::fishQuote('Show this help text') . "\n";
            foreach ($cmd->options() as $o) {
                $line = $c . $cond . ' ' . (\strlen($o->name) === 1 ? '-s ' : '-l ') . $o->name;
                if ($o->kind === ArgParse::VALUE) {
                    $line .= self::isPath($o) ? ' -r -F' : ' -r -f';
                }
                $out .= $line . ' -d ' . self::fishQuote($o->help) . "\n";
            }
        }
        return $out;
    }
}
