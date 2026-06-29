<?php

use Manticore\Attr\Project;
use Manticore\Attr\Module;
use Manticore\Attr\Entry;

#[Project('manticore-selfhost')]
final class Manifest
{
    #[Module('src/Cli')]
    public string $cli = '';

    #[Module('src/Codegen')]
    public string $codegen = '';

    #[Module('src/Compile')]
    public string $compile = '';

    #[Module('src/Ffi')]
    public string $ffi = '';

    #[Module('src/Lexer')]
    public string $lexer = '';

    #[Module('src/Manticore')]
    public string $manticore = '';

    #[Module('src/Os')]
    public string $os = '';

    #[Module('src/Parser')]
    public string $parser = '';

    #[Module('src/Runtime')]
    public string $runtime = '';

    #[Entry]
    public string $entry = 'src/zzz_entry.php';
}
