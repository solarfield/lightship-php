<?php
namespace Solarfield\Lightship;

use Solarfield\Ok\StructUtils;
use Throwable;

abstract class Controller extends \Solarfield\Batten\Controller {
	static public function bootstrap() {
		$exitCode = 1;

		try {
			if (($controller = static::boot(static::processInitialRoute()))) {
				try {
					$controller->connect();
					$controller->run();
					$exitCode = 0;
				}
				catch (Throwable $ex) {
					$controller->handleException($ex);
				}
			}
		}

		catch (Throwable $ex) {
			static::bail($ex);
		}

		return $exitCode;
	}
	
	static public function processInitialRoute() {
		return null;
	}

	private function resolvePluginDependencies_step($plugin) {
		$plugins = $this->getPlugins();

		//if plugin is Lightship-compatible
		if ($plugin instanceof ControllerPlugin) {
			foreach ($plugin->getManifest()->getAsArray('dependencies.plugins') as $dep) {
				if (StructUtils::search($plugins->getRegistrations(), 'componentCode', $dep['code']) === false) {
					if (($depPlugin = $plugins->register($dep['code']))) {
						$this->resolvePluginDependencies_step($depPlugin);
					}
				}
			}
		}
	}

	private function resolvePluginDependencies() {
		$plugins = $this->getPlugins();

		foreach ($plugins->getRegistrations() as $registration) {
			if (($plugin = $plugins->get($registration['componentCode']))) {
				$this->resolvePluginDependencies_step($plugin);
			}
		}
	}

	public function init() {
		$this->resolvePlugins();
		$this->resolvePluginDependencies();

		$this->resolveOptions();
	}
}
