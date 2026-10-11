<?php
$l = new \Acme\Cfg\Loader\Loader();
print_r($l->load('svc.php')->log);
print_r($l->data('bundles.php'));
$v = $l->data('versions.php');
echo $v['root']['name'], ' ', basename($v['root']['path']), "\n";
