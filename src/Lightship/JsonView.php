<?php
namespace Lightship;

use Batten\Event;
use Batten\Options;
use Batten\Reflector;
use Ok\JsonUtils;
use Ok\StructUtils;

class JsonView extends View {
	private $rules;

	protected function resolveDataRules() {
		$rules = $this->getDataRules();
		$rules->set('app.standardOutput', true);

		if (Reflector::inSurfaceOrModuleMethodCall()) {
			$this->dispatchEvent(
				new Event('app-resolve-data-rules', ['target' => $this])
			);
		}
	}

	public function getDataRules() {
		if (!$this->rules) {
			//TODO: should not really use Options here
			$this->rules = new Options();
		}

		return $this->rules;
	}

	public function createJsonData() {
		$jsonData = [];
		$model = $this->getModel();
		$rules = $this->getDataRules()->toArray();

		foreach ($rules as $k => $v) {
			if ($v) {
				$s = StructUtils::scout($model, $k);

				if ($s[0]) {
					StructUtils::set($jsonData, $k, $s[1]);
				}
			}
		}

		if (Reflector::inSurfaceOrModuleMethodCall()) {
			$buffer = [];

			$this->dispatchEvent(
				new ArrayBufferEvent('app-create-json-data', ['target' => $this], $buffer)
			);

			if (count($buffer) > 0) {
				$jsonData = StructUtils::merge($jsonData, $buffer);
			}
		}

		return $jsonData;
	}

	public function createJson() {
		return JsonUtils::toJson($this->createJsonData());
	}

	public function render() {
		header('Content-Type: application/json');
		echo($this->createJson());
	}

	public function init() {
		parent::init();
		$this->resolveDataRules();
	}

	public function __construct($aCode) {
		$this->type = 'Json';
		parent::__construct($aCode);
	}
}
