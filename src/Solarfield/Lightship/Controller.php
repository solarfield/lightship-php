<?php
namespace Solarfield\Lightship;

use Exception;
use Solarfield\Lightship\Errors\UnresolvedRouteException;
use Solarfield\Lightship\Events\ResolveOptionsEvent;
use Solarfield\Ok\EventTargetTrait;
use Solarfield\Ok\MiscUtils;
use Solarfield\Ok\StructUtils;
use Throwable;

abstract class Controller implements ControllerInterface {
	use EventTargetTrait;
	
	static public function fromContext(EnvironmentInterface $aEnvironment, ContextInterface $aContext): ControllerInterface {
		$moduleCode = $aContext->getRoute()->getModuleCode();
		$options = $aContext->getRoute()->getControllerOptions();
		
		$component = (new ComponentResolver($aEnvironment))->resolveComponent(
			$aEnvironment->getComponentChain($moduleCode),
			'Controller',
			null,
			null
		);
		
		if (!$component) {
			throw new \Exception(
				"Could not resolve Controller component for module '" . $moduleCode . "'."
				. " No component class files could be found."
			);
		}
		
		/** @noinspection PhpIncludeInspection */
		include_once $component['includeFilePath'];
		
		if (!class_exists($component['className'])) {
			throw new \Exception(
				"Could not resolve Controller component for module '" . $moduleCode . "'."
				. " No component class was found in include file '" . $component['includeFilePath'] . "'."
			);
		}
		
		/** @var Controller $controller */
		$controller = new $component['className']($aEnvironment, $moduleCode, $aContext, $options);
		
		return $controller;
	}
	
	static public function route(EnvironmentInterface $aEnvironment, ContextInterface $aContext): ContextInterface {
		return $aContext;
	}
	
	static public function bootstrap(EnvironmentInterface $aEnvironment, ContextInterface $aContext) {
		$exitCode = 1;
		
		try {
			if (($controller = static::boot($aEnvironment, $aContext))) {
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
			static::bail($aEnvironment, $ex);
		}
		
		return $exitCode;
	}
	
	static public function boot(EnvironmentInterface $aEnvironment, ContextInterface $aContext) {
		if ($aEnvironment->getVars()->get('logMemUsage')) {
			$bytesUsed = memory_get_usage();
			$bytesLimit = ini_get('memory_limit');
			
			$aEnvironment->getLogger()->debug(
				'mem[boot begin]: ' . ceil($bytesUsed/1024) . 'K/' . $bytesLimit
				. ' ' . round($bytesUsed/\Solarfield\Ok\PhpUtils::parseShorthandBytes($bytesLimit)*100, 2) . '%'
			);
			
			unset($bytesUsed, $bytesLimit);
		}
		
		if ($aEnvironment->getVars()->get('logPaths')) {
			$aEnvironment->getLogger()->debug('App dependencies file path: '. $aEnvironment->getVars()->get('appDependenciesFilePath'));
			$aEnvironment->getLogger()->debug('App package file path: '. $aEnvironment->getVars()->get('appPackageFilePath'));
		}
		
		$context = static::route($aEnvironment, $aContext);
		$nextStep = $context->getRoute()->getNextStep();
		
		$stubController = static::fromContext($aEnvironment, $context);
		$stubController->init();
		
		$finalController = null;
		$finalError = null;
		
		try {
			$finalController = $stubController->bootDynamic($context);
		}
		catch (Throwable $ex) {
			$finalError = $ex;
		}
		
		//if we couldn't route, but we didn't encounter an exception
		if (!$finalController && !$finalError) {
			//imply a 'could not route' error
			
			$message = "Could not route: " . (is_scalar($nextStep) ? "'$nextStep'" : MiscUtils::varInfo($nextStep)) . ".";
			
			$finalError = new UnresolvedRouteException(
				$message, 0, null,
				[
					'bootPath' => $context->getBootPath(),
				]
			);
			
			unset($nextStep, $message);
		}
		
		if ($finalError) {
			//if the boot loop was already recovered previously
			if ($context->getBootRecoveryCount() > 0) {
				//don't attempt to recover again, to avoid causing an infinite loop
				throw new Exception(
					"Unrecoverable boot loop error.",
					0, $finalError
				);
			}
			
			//let the stub controller handle the exception
			$stubController->handleException($finalError);
		}
		
		if ($aEnvironment->getVars()->get('logMemUsage')) {
			$bytesUsed = memory_get_usage();
			$bytesPeak = memory_get_peak_usage();
			$bytesLimit = ini_get('memory_limit');
			
			$aEnvironment->getLogger()->debug(
				'mem[boot end]: ' . ceil($bytesUsed/1024) . 'K/' . $bytesLimit
				. ' ' . round($bytesUsed/\Solarfield\Ok\PhpUtils::parseShorthandBytes($bytesLimit)*100, 2) . '%'
			);
			
			$aEnvironment->getLogger()->debug(
				'mem-peak[boot end]: ' . ceil($bytesPeak/1024) . 'K/' . $bytesLimit
				. ' ' . round($bytesPeak/\Solarfield\Ok\PhpUtils::parseShorthandBytes($bytesLimit)*100, 2) . '%'
			);
			
			unset($bytesUsed, $bytesPeak, $bytesLimit);
			
			$bytesUsed = realpath_cache_size();
			$bytesLimit = ini_get('realpath_cache_size');
			
			$aEnvironment->getLogger()->debug(
				'realpath-cache-size[boot end]: ' . (ceil($bytesUsed/1024)) . 'K/' . $bytesLimit
				. ' ' . round($bytesUsed/\Solarfield\Ok\PhpUtils::parseShorthandBytes($bytesLimit)*100, 2) . '%'
			);
			
			unset($bytesUsed, $bytesLimit);
		}
		
		return $finalController;
	}
	
	/**
	 * Will be called by ::bootstrap() if an uncaught error occurs before a Controller is created.
	 * Normally this is only called when in an unrecoverable error state.
	 * @see ::handleException().
	 * @param EnvironmentInterface $aEnvironment
	 * @param Throwable $aEx
	 */
	static public function bail(EnvironmentInterface $aEnvironment, Throwable $aEx) {
		$aEnvironment->getLogger()->error("Bailed.", ['exception'=>$aEx]);
	}
	
	private $environment;
	private $context;
	private $code;
	private $hints;
	private $model;
	private $view;
	private $defaultViewType;
	private $options;
	private $plugins;
	private $proxy;
	private $logger;
	private $componentResolver;
	
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
	
	protected function getContext(): ContextInterface {
		return $this->context;
	}
	
	protected function resolvePlugins() {
	
	}
	
	protected function resolveOptions() {
		$event = new ResolveOptionsEvent('resolve-options', ['target' => $this]);
		
		$this->dispatchEvent($event, [
			'listener' => [$this, 'onResolveOptions'],
		]);
		
		$this->dispatchEvent($event);
	}
	
	protected function onResolveOptions(ResolveOptionsEvent $aEvt) {
	
	}
	
	public function bootDynamic(ContextInterface $aContext) {
		//this remains true until the boot loop stops.
		//During each iteration of the boot loop, controllers are created and asked to provide the next step in the route.
		//Once the same step is returned twice (i.e. no movement), we consider the route successfully processed, and the
		//last created controller is returned. Note that the controller will have a model and input already attached.
		//If any controller during the loop routes to null, we stop and consider the route unsuccessfully processed.
		$keepRouting = true;
		
		/** @var Controller $tempController */
		$tempController = null;
		
		$model = $this->createModel();
		$model->init();
		
		//the temporary boot info passed along through the boot loop
		//The only data/keys kept are moduleCode, nextStep, controllerOptions
		$tempContext = $aContext;
		
		$loopCount = 0;
		do {
			if ($tempContext != null) {
				$aContext->getInput()->merge($tempContext->getRoute()->getInput());
				$aContext->getHints()->merge($tempContext->getRoute()->getHints());
				
				//create a unique key representing this iteration of the loop.
				//This is used to detect infinite loops, due to a later iteration routing back to an earlier iteration
				$tempIteration = implode('+', [
					$tempContext->getRoute()->getModuleCode(),
					$tempContext->getRoute()->getNextStep(),
				]);
				
				//if we don't have a temp controller yet,
				//or the temp controller is not the target controller (comparing by module code)
				//or we still have routing to do
				if ($tempController == null || $tempContext->getRoute()->getModuleCode() != $tempController->getCode() || $tempContext->getRoute()->getNextStep() !== null) {
					//if the current iteration has not been encountered before
					if (!in_array($tempIteration, $tempContext->getBootPath())) {
						//append the current iteration to the boot path
						$tempContext = $tempContext->withAddedBootStep($tempIteration);
						
						//if we already have a temp controller
						if ($tempController) {
							//tell it to create the target controller
							$tempController = $tempController::fromContext($this->getEnvironment(), $tempContext);
							$tempController->init();
						}
						
						//else we don't have a controller yet
						else {
							//if the target controller's code is the same as the current controller
							if ($tempContext->getRoute()->getModuleCode() == $this->getCode()) {
								//use the current controller as the target controller
								$tempController = $this;
							}
							
							//else the target controller's code is different that the current controller
							else {
								//tell the current controller to create the target controller
								$tempController = $this::fromContext($this->getEnvironment(), $tempContext);
								$tempController->init();
							}
						}
						
						//attach the model to the new temp controller
						$tempController->setModel($model);
						
						//if we have routing to do
						if ($tempContext->getRoute()->getNextStep() != null || $loopCount == 0) {
							//tell the temp controller to process the route
							$newContext = $tempController->routeDynamic($tempContext);
							
							if ($this->getEnvironment()->getVars()->get('logRouting')) {
								$this->getLogger()->debug(get_class($tempController) . ' routed from -> to: ' . MiscUtils::varInfo($tempContext->getRoute()) . ' -> ' . MiscUtils::varInfo($newContext->getRoute()));
							}
							
							$tempContext = $newContext;
							unset($newContext);
							
							//if we get here, the next iteration of the boot loop will now occur
						}
					}
					
					//else the current iteration is a duplication of an earlier iteration
					else {
						//we have detected an infinite boot loop, and cannot resolve the controller
						
						$tempController = null;
						$keepRouting = false;
						
						//append the current iteration to the boot path
						$tempContext = $tempContext->withAddedBootStep($tempIteration);
					}
				}
				
				//else we don't have any routing to do
				else {
					$keepRouting = false;
				}
			}
			
			//else $tempInfo is null
			else {
				//if we get here, we could not resolve the final controller
				
				//clear any temp controller as it does not represent the final controller
				$tempController = null;
				
				$keepRouting = false;
			}
			
			$loopCount++;
		}
		while ($keepRouting);
		
		if ($tempController) {
			$tempController->markResolved();
		}
		
		return $tempController;
	}
	
	public function markResolved() {
	
	}
	
	public function routeDynamic(ContextInterface $aContext): ContextInterface {
		return $aContext;
	}
	
	public function connect() {
		$viewType = $this->getRequestedViewType();
		
		if ($viewType != null) {
			$view = $this->createView($viewType);
			$view->setController($this->getProxy());
			$view->init();
			
			$input = $view->getInput();
			if ($input) {
				$this->getInput()->mergeReverse($input);
			}
			unset($input);
			
			$hints = $view->getHints();
			if ($hints) {
				$this->getHints()->mergeReverse($hints);
			}
			unset($hints);
			
			$view->setModel($this->getModel());
			
			$this->setView($view);
		}
	}
	
	public function run() {
		$this->runTasks();
		$this->runRender();
	}
	
	/**
	 * Will be called by ::bootstrap() if an uncaught error occurs after a Controller is created.
	 * Normally this is only called when ::connect() or ::run() fails.
	 * You can override this method, and attempt to boot another Controller for recovery purposes, etc.
	 * @see ::bail().
	 * @param Throwable $aEx
	 */
	public function handleException(Throwable $aEx) {
		static::bail($this->getEnvironment(), $aEx);
	}
	
	public function getComponentResolver() {
		if (!$this->componentResolver) {
			$this->componentResolver = new ComponentResolver($this->getEnvironment(), [
				'logger' => $this->getLogger()->withName($this->getLogger()->getName() . '/componentResolver'),
			]);
		}
		
		return $this->componentResolver;
	}
	
	public function getDefaultViewType() {
		return $this->defaultViewType;
	}
	
	public function setDefaultViewType($aType) {
		$this->defaultViewType = (string)$aType;
	}
	
	public function getHints() {
		return $this->getContext()->getHints();
	}
	
	/**
	 * @return InputInterface
	 */
	public function getInput() {
		return $this->getContext()->getInput();
	}
	
	public function setModel(ModelInterface $aModel) {
		$this->model = $aModel;
	}
	
	/**
	 * @return ModelInterface
	 */
	public function getModel() {
		return $this->model;
	}
	
	public function createModel() {
		$code = $this->getCode();
		
		$component = $this->getComponentResolver()->resolveComponent(
			$this->getEnvironment()->getComponentChain($code),
			'Model'
		);
		
		if (!$component) {
			throw new \Exception(
				"Could not resolve Model component for module '" . $code . "'."
				. " No component class files could be found."
			);
		}
		
		/** @noinspection PhpIncludeInspection */
		include_once $component['includeFilePath'];
		
		if (!class_exists($component['className'])) {
			throw new \Exception(
				"Could not resolve Model component for module '" . $code . "'."
				. " No component class was found in include file '" . $component['includeFilePath'] . "'."
			);
		}
		
		$model = new $component['className']($this->getEnvironment(), $code);
		
		return $model;
	}
	
	public function createView($aType) {
		$code = $this->getCode();
		
		$component = $this->getComponentResolver()->resolveComponent(
			$this->getEnvironment()->getComponentChain($code),
			'View',
			$aType
		);
		
		if (!$component) {
			throw new \Exception(
				"Could not resolve " . $aType . " View component for module '" . $code . "'."
				. " No component class files could be found."
			);
		}
		
		/** @noinspection PhpIncludeInspection */
		include_once $component['includeFilePath'];
		
		if (!class_exists($component['className'])) {
			throw new \Exception(
				"Could not resolve " . $aType . " View component for module '" . $code . "'."
				. " No component class was found in include file '" . $component['includeFilePath'] . "'."
			);
		}
		
		$view = new $component['className']($this->getEnvironment(), $code);
		
		return $view;
	}
	
	public function getView() {
		return $this->view;
	}
	
	public function setView(ViewInterface $aView) {
		$this->view = $aView;
	}
	
	public function getProxy() {
		if (!$this->proxy) {
			$this->proxy = new ControllerProxy($this);
		}
		
		return $this->proxy;
	}
	
	public function getCode() {
		return $this->code;
	}
	
	public function getOptions() {
		if (!$this->options) {
			$this->options = new Options();
		}
		
		return $this->options;
	}
	
	public function getPlugins() {
		if (!$this->plugins) {
			$this->plugins = new ControllerPlugins($this);
		}
		
		return $this->plugins;
	}
	
	public function getLogger() {
		if (!$this->logger) {
			$this->logger = $this->getEnvironment()->getLogger()->withName('controller[' . $this->getCode() . ']');
		}
		
		return $this->logger;
	}
	
	public function getEnvironment(): EnvironmentInterface {
		return $this->environment;
	}
	
	public function init() {
		//this method provides a hook to resolve plugins, options, etc.
		
		$this->resolvePlugins();
		$this->resolvePluginDependencies();
		$this->resolveOptions();
	}
	
	public function __construct(EnvironmentInterface $aEnvironment, $aCode, ContextInterface $aContext, $aOptions = []) {
		$this->environment = $aEnvironment;
		$this->componentResolver = new ComponentResolver($aEnvironment);
		$this->context = $aContext;
		$this->code = (string)$aCode;
		
		if ($aEnvironment->getVars()->get('logComponentLifetimes')) {
			$this->getLogger()->debug(get_class($this) . "[code=" . $aCode . "] was constructed");
		}
	}
	
	public function __destruct() {
		if ($this->getEnvironment()->getVars()->get('logComponentLifetimes')) {
			$this->getLogger()->debug(get_class($this) . "[code=" . $this->getCode() . "] was destructed");
		}
	}
}
