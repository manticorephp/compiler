<?php
// @epic: include-semantics
// @why: `require` of a compiled-in data file handed back the include slot's array as if it were a
//       fresh value, so the caller's release of it spent the slot's only count: a holder property
//       and the slot both dangled after the first consumer dropped the array (Laravel's
//       PackageManifest::getManifest, first request, heap corruption).
class Holder
{
    public $manifest;

    public function get()
    {
        if (!is_null($this->manifest)) {
            return $this->manifest;
        }
        return $this->manifest = (static function () {
            return require __DIR__ . '/data.php';
        })();
    }
}

function providers(array $manifest): array
{
    $out = [];
    foreach ($manifest as $package) {
        foreach ($package['providers'] as $p) {
            $out[] = $p;
        }
    }
    return $out;
}

$h = new Holder();
for ($i = 0; $i < 3; $i++) {
    $copy = $h->get();
    echo implode(',', providers($copy)), "\n";
    unset($copy);
}
$direct = require __DIR__ . '/data.php';
unset($direct);
echo count($h->get()), ' ', count(require __DIR__ . '/data.php'), "\n";
