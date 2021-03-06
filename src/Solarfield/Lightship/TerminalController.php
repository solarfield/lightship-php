<?php
namespace Solarfield\Lightship;

use Solarfield\Lightship\Events\DoTaskEvent;
use Throwable;

/**
 * Class TerminalController
 * @package Solarfield\Lightship
 *
 * @method TerminalSourceContext getContext
 */
abstract class TerminalController extends Controller {
	protected function executeScript() {
		//NOTE: override this method to do your module-specific stuff
	}

	public function getRequestedViewType() {
		$type = trim($this->getInput()->getAsString('--view'));

		if ($type === '') $type = $this->getDefaultViewType();

		return $type;
	}

	public function run(): DestinationContextInterface {
		// connect the view
		$view = $this->createView($this->getRequestedViewType());
		$this->getInput()->mergeReverse($view->getInput());
		$this->getHints()->mergeReverse($view->getHints());

		$destinationContext = new TerminalDestinationContext();

		$destinationContext = $this->doTask($destinationContext);
		$destinationContext = $view->render($destinationContext);

		return $destinationContext;
	}

	public function onDoTask(DoTaskEvent $aEvt) {
		$startTime = microtime(true);

		$input = $this->getInput();
		$stdout = $this->getEnvironment()->getStandardOutput();

		$verbose = (bool)$input->getAsString('--verbose');

		//check if the resolved controller is App\Controller (i.e. no matching module exists).
		//If it is, the --module argument was probably mistyped.
		$thisClass = get_class($this);
		if ($thisClass == 'App\Controller') {
			$stdout->warning("Warning: Script controller resolved to app-level '$thisClass'.");
		}

		if ($verbose) {
			$stdout->write("Script '" . $this->getCode() . "' started at " . date('c', $startTime) . '.');
		}

		$this->executeScript();

		$endTime = microtime(true);

		if ($verbose) {
			$stdout->write("Script '" . $this->getCode() . "' ended at " . date('c', $endTime) . '.');
			$stdout->write('Script duration was ' . round($endTime - $startTime, 2) . ' seconds.');
		}
	}

	public function handleException(Throwable $aEx) : DestinationContextInterface {
		return $this->getEnvironment()->bail($aEx);
	}

	public function __construct(EnvironmentInterface $aEnvironment, $aCode, ModelInterface $aModel, SourceContextInterface $aContext, $aOptions = []) {
		parent::__construct($aEnvironment, $aCode, $aModel, $aContext, $aOptions);

		$this->setDefaultViewType('Stdout');
	}
}
