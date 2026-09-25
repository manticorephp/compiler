<?php
// __NAMESPACE__ is the parse-time namespace (it was '' everywhere), and the
// discovery idiom built on it instantiates the right class.
namespace App\Rep;

interface R { public function getFormat(): string; }
final class JsonReporter implements R { public function getFormat(): string { return 'json'; } }
final class TxtReporter implements R { public function getFormat(): string { return 'txt'; } }

function reg(R $r): string { return $r->getFormat(); }
function ns(): string { return __NAMESPACE__; }

echo __NAMESPACE__, "\n", ns(), "\n";
foreach (['Json', 'Txt'] as $b) {
    $c = \sprintf('%s\%s%s', __NAMESPACE__, '', $b . 'Reporter');
    echo $c, ' => ', reg(new $c()), "\n";
}
