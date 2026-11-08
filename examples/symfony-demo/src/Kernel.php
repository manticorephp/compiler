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
