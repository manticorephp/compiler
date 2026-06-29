<?php
use Manticore\Attr\Project;
use Manticore\Attr\Module;
use Manticore\Attr\Entry;

#[Project('demo')]
final class Manifest {
    #[Module('src')] public string $app;
    #[Entry]         public string $entry = 'src/main.php';
}
