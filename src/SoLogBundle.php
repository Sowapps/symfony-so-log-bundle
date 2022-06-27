<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

namespace Sowapps\SoLog;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SoLogBundle extends AbstractBundle {
	
	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void {
		// Load service configuration
		$container->import('../config/services.yaml');
	}
	
}
