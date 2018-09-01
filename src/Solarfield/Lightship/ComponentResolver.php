<?php
namespace Solarfield\Lightship;

use Solarfield\Ok\LoggerInterface;

class ComponentResolver {
	/** @var LoggerInterface */ private $logger;
	/** @var EnvironmentInterface */ private $environment;
	
	public function resolveComponent(ComponentChain $aChain, $aClassNamePart, $aViewTypeCode = null, $aPluginCode = null) {
		// reverse the chain
		/** @var ComponentChainLink[] $chain */ $chain = [];
		foreach ($aChain as $link) {
			$chain[] = $link;
		}
		$chain = array_reverse($chain);
		
		$component = null;

		foreach ($chain as $link) {
			$classNamespace = $link->namespace();
			$className = ($aViewTypeCode ?: '') . $aClassNamePart;
			$classFileName = $className . '.php';
			$includePath = $link->path();
			
			if ($aPluginCode) {
				$pluginNamespace = $aPluginCode;
				$pluginDir = $pluginNamespace;

				$classNamespace .= $link->pluginsNamespace();
				$classNamespace .= '\\' . $pluginNamespace;

				$includePath .= $link->pluginsPath();
				$includePath .= '/' . $pluginDir;
			}
			
			$includePath .= '/' . $classFileName;
			$realIncludePath = realpath($includePath);

			if ($realIncludePath !== false) {
				$component = [
					'className' => $classNamespace . '\\' . $className,
					'includeFilePath' => $realIncludePath,
				];

				break;
			}
		}

		if ($this->environment->getVars()->get('logComponentResolution')) {
			$this->logger->debug(
				"Resolved component '" . ($component ? $component['className'] : 'NULL') . "'.",
				
				[
					'classNamePart' => $aClassNamePart,
					'viewTypeCode' => $aViewTypeCode,
					'pluginCode' => $aPluginCode,
					'chain' => $chain,
					'component' => $component,
				]
			);
		}

		return $component;
	}
	
	public function __construct(EnvironmentInterface $aEnvironment, array $aOptions = null) {
		$this->environment = $aEnvironment;
		
		$options = array_replace([
			'logger' => null,
		], $aOptions ?: []);
		
		if ($options['logger']) {
			if (!$options['logger'] instanceof LoggerInterface) throw new \Exception(
				"Option 'logger' must be an instance of \Solarfield\Ok\LoggerInterface."
			);
			
			$this->logger = $options['logger'];
		}
		else {
			$this->logger = $this->environment->getLogger();
		}
	}
}
