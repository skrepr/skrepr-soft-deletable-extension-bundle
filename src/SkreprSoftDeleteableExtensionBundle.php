<?php

declare(strict_types=1);

namespace Skrepr\SkreprSoftDeleteableExtensionBundle;

use StichtingSD\SoftDeleteableExtensionBundle\StichtingSDSoftDeleteableExtensionBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class SkreprSoftDeleteableExtensionBundle extends StichtingSDSoftDeleteableExtensionBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('metadata_cache')->defaultValue('cache.adapter.array')->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($config['metadata_cache'] !== 'cache.adapter.array') {
            $builder->setAlias('stichtingsd.softdeleteable_extension.cache', $config['metadata_cache']);
        } else {
            $container->services()->set('stichtingsd.softdeleteable_extension.cache')->parent('cache.adapter.array');
        }

        $container->import('../config/services.php');
    }
}
