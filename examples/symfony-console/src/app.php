<?php

// `composer: true` in ../manticore.json compiles Symfony and this PSR-4 source
// into the binary. There is deliberately no runtime `vendor/autoload.php` here.

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

$app = new Application('Manticore Symfony Console demo');

$app
    ->register('greet')
    ->setDescription('Greet someone from a native binary')
    ->addArgument('name', InputArgument::OPTIONAL, 'Name to greet', 'world')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $output->writeln('Hello, ' . $input->getArgument('name') . '!');
        return 0;
    });

$app->run();
