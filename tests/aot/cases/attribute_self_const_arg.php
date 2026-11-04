<?php
#[Attribute]
final class Cmd { public function __construct(public string $name, public string $help = '') {} }
#[Cmd(name: 'app:add', help: self::HELP)]
final class AddCmd {
    public const HELP = 'adds a thing';
    #[Cmd('m', self::HELP . '!')]
    public function run(): void {}
}
$r = new ReflectionClass(AddCmd::class);
$a = $r->getAttributes()[0]->newInstance();
echo $a->name, '|', $a->help, "\n";
echo json_encode($r->getAttributes()[0]->getArguments()), "\n";
$m = $r->getMethod('run')->getAttributes()[0]->newInstance();
echo $m->name, '|', $m->help, "\n";
