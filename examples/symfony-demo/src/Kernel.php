<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private static ?ContainerBuilder $compiled = null;

    private function getAllowedEnvs(): array
    {
        return ['dev', 'prod', 'test'];
    }

    public function getBuildDir(): string
    {
        return $this->getProjectDir().'/var/build/'.$this->environment;
    }

    /** config/bundles.php is a runtime `require`; a compiled binary does not execute it. */
    public function registerBundles(): iterable
    {
        yield new \Symfony\Component\DependencyInjection\Kernel\ServicesBundle();
        yield new \Symfony\Component\Console\ConsoleBundle();
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new \Symfony\Bundle\SecurityBundle\SecurityBundle();
        yield new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
        yield new \Symfony\Bundle\MonologBundle\MonologBundle();
        yield new \Symfony\Bundle\TwigBundle\TwigBundle();
        yield new \Twig\Extra\TwigExtraBundle\TwigExtraBundle();
        yield new \Symfony\UX\TwigComponent\TwigComponentBundle();
        yield new \Symfony\UX\LiveComponent\LiveComponentBundle();
        yield new \Symfony\UX\StimulusBundle\StimulusBundle();
        yield new \Symfonycasts\SassBundle\SymfonycastsSassBundle();
        yield new \Symfony\UX\Icons\UXIconsBundle();
    }

    /**
     * The system cache pool (Doctrine's parsed queries, ux-icons, validator metadata) in process memory.
     * Its default, PhpFilesAdapter, writes PHP files and `include`s them back, which a compiled binary
     * does not do: every save failed, every request recomputed, and the flock/fopen of that path is
     * where a second request cut in. One process serves every request, so memory is the shared cache —
     * as long as the kernel does not reset it: an array pool is tagged `kernel.reset`, and the next
     * handle() emptied it, so every request still re-read its icons and re-parsed its queries.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', ['cache' => ['system' => 'cache.adapter.array']]);
        // Between CachePoolPass (32), which adds the tag, and ResettableServicePass (-32), which collects it.
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->findTaggedServiceIds('cache.pool') as $id => $_) {
                    $container->getDefinition($id)->clearTag('kernel.reset');
                }
            }
        }, \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
    }

    /** The container is compiled in memory once per process, not dumped to var/cache and included. */
    protected function initializeContainer(): void
    {
        $container = self::$compiled;
        if ($container === null) {
            $container = $this->buildContainer();
            if (!$container->hasParameter('container.runtime_mode')) {
                $container->setParameter('container.runtime_mode', 'web=1');
            }
            // PhpDumper bakes these into the dumped container; the in-memory one has no dumper.
            foreach (['container.build_hash' => 'mc', 'container.build_id' => 'mc-demo', 'container.build_time' => 0] as $k => $v) {
                if (!$container->hasParameter($k)) { $container->setParameter($k, $v); }
            }
            // Lazy services are proxies, and ReflectionClass::newLazyProxy is not supported: build them eagerly.
            // (Doctrine's lazy entities are ghosts, which are: see "lazy" in manticore.json.)
            $container->setProxyInstantiator(new \Symfony\Component\DependencyInjection\LazyProxy\Instantiator\RealServiceInstantiator());
            $container->compile(true);
            self::$compiled = $container;
        }
        $this->container = $container;
        $container->set('kernel', $this);
    }
}
