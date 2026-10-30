<?php
final class Settings
{
    public array $packages;

    public function __construct(string $path, array $composerExtra)
    {
        if (isset($composerExtra['config-file'])) {
            /** @var array $extra */
            $extra = require $path . '/' . $composerExtra['config-file'];
        } else {
            $extra = $composerExtra;
        }
        $this->packages = (array) ($extra['packages'] ?? []);
    }
}
$f = sys_get_temp_dir() . '/mc_require_branch_join.php';
file_put_contents($f, '<?php return ["packages" => ["a" => 1]];');
var_dump((new Settings(dirname($f), ['config-file' => basename($f)]))->packages);
var_dump((new Settings(dirname($f), ['packages' => ['b' => 2]]))->packages);
unlink($f);
