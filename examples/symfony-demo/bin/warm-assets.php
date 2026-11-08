<?php
// Run under php BEFORE `manticore build`: php bin/warm-assets.php "$PWD"  (writes public/assets, which the binary serves as static files).
// Dev tool (runs under Zend). The icon cache warmer is left out: it is a cache, and it fails under php on this vendor set.
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
$root = $argv[1];
require_once $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');
putenv('APP_SECRET=warm'); $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'warm';
class AssetKernel extends Kernel {
    public function getProjectDir(): string { return $GLOBALS['root']; }
    public function getCacheDir(): string { return $GLOBALS['root'] . '/var/cache/assets'; }
    protected function build(ContainerBuilder $c): void {
        parent::build($c);
        $c->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $c): void { $c->removeDefinition('.ux_icons.event_listener.warm_icon_cache_on_asset_compile'); }
        });
    }
}
$app = new Application(new AssetKernel('prod', false));
$app->setAutoExit(false);
exit($app->run(new ArrayInput(['command' => 'asset-map:compile']), new ConsoleOutput()));
