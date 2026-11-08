<?php
// Run under php BEFORE `manticore build`: php bin/warm-twig.php "$PWD"  (writes var/cache/prod/twig, listed in manticore.json src).
// Dev tool (runs under Zend): compile every Twig template into the cache dir the binary will include as units.
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Twig\Loader\FilesystemLoader;
$root = $argv[1];
require_once $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');
putenv('APP_SECRET=warm'); $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'warm';
class WarmKernel extends Kernel { public function getProjectDir(): string { return $GLOBALS["root"]; } protected function build(\Symfony\Component\DependencyInjection\ContainerBuilder $c): void { parent::build($c); $c->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface { public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $c): void { $c->getDefinition("twig")->setPublic(true); } }); } }
$kernel = new WarmKernel("prod", false);
$kernel->boot();
$twig = $kernel->getContainer()->get('twig');
$loader = $twig->getLoader();
$n = 0;
foreach ($loader->getNamespaces() as $ns) {
    foreach ($loader->getPaths($ns) as $dir) {
        if (!is_dir($dir)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.twig')) { continue; }
            $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
            $name = $ns === FilesystemLoader::MAIN_NAMESPACE ? $rel : '@' . $ns . '/' . $rel;
            try { $twig->load($name); $n++; } catch (\Throwable $e) { fwrite(STDERR, "skip $name: " . substr($e->getMessage(), 0, 100) . "\n"); }
        }
    }
}
// Translation catalogues are `new MessageCatalogue(...)` scripts: a binary cannot read one back, it has to be a unit.
$translator = $kernel->getContainer()->get('translator');
// The default locale first: as another locale's fallback it would load in memory and never reach the cache.
$translator->getCatalogue($translator->getLocale());
foreach ($kernel->getContainer()->getParameter('kernel.enabled_locales') as $locale) { $translator->getCatalogue($locale); }
echo "warmed $n templates; cache=", $kernel->getCacheDir(), "\n";
