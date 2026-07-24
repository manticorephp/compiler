<?php
$base = ['name' => 'old', 'config' => ['host' => 'one', 'port' => 80], 'keep' => 7];
$override = ['name' => 'new', 'config' => ['port' => 443, 'tls' => true], 'added' => 9];
$result = array_replace_recursive($base, $override);
echo $result['name'], ":", $result['keep'], ":", $result['added'], "\n";
echo $result['config']['host'], ":", $result['config']['port'], ":", $result['config']['tls'] ? 'yes' : 'no', "\n";
