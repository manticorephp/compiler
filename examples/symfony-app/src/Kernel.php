<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** compiled ContainerBuilder shared by every Kernel instance of this process */
    private static ?ContainerBuilder $compiled = null;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * The container lives in memory, so the build dir stays empty: a stale var/cache/<env>/*.bundles.php
     * would be `require`d by initializeBundles(), and a compiled binary does not execute `require`.
     */
    public function getBuildDir(): string
    {
        return $this->getProjectDir().'/var/build/'.$this->environment;
    }

    /**
     * Static bundle list: config/bundles.php is a runtime `require`, which a compiled binary does not execute.
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    /**
     * Build and compile the container in memory on first use, instead of dumping it to
     * var/cache and `include`-ing the generated class.
     */
    protected function initializeContainer(): void
    {
        $container = self::$compiled;
        if ($container === null) {
            $container = $this->buildContainer();
            if (!$container->hasParameter('container.runtime_mode')) {
                $container->setParameter('container.runtime_mode', 'web=1');
            }
            $container->compile(true);
            self::$compiled = $container;
        }
        $this->container = $container;
        $container->set('kernel', $this);
    }
}
