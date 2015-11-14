<?php
namespace Lightship;

use Ok\StringUtils;
use Ok\StructUtils;

class WebInput implements \Batten\InputInterface {
	private $data = [];

	private function normalize($aArray) {
		$arr = [];

		foreach ($aArray as $k => $v) {
			$k = StringUtils::dashToCamel($k);
			$arr[$k] = is_array($v) ? $this->normalize($v) : $v;
		}

		return $arr;
	}

	public function getAsString($aPath) {
		return StructUtils::get($this->data, $aPath);
	}

	public function getAsArray($aPath) {
		$value = StructUtils::get($this->data, $aPath);
		return is_array($value) ? $value : [];
	}

	public function toArray() {
		return $this->data;
	}

	public function set($aPath, $aValue) {
		StructUtils::set($this->data, $aPath, $aValue);
	}

	public function merge($aData) {
		$incomingData = StructUtils::toArray($aData, true);
		$incomingData = StructUtils::unflatten($incomingData, '.');

		$this->data = StructUtils::merge($this->data, $incomingData);
	}

	public function mergeReverse($aData) {
		$incomingData = StructUtils::toArray($aData, true);
		$incomingData = StructUtils::unflatten($incomingData, '.');

		$this->data = StructUtils::merge($incomingData, $this->data);
	}

	public function importFromGlobals() {
		$globals = [$_GET, $_POST, $_FILES];

		foreach ($globals as $global) {
			$globalData = $this->normalize($global);
			$globalData = StructUtils::unflatten($globalData, '_');
			$this->data = StructUtils::merge($this->data, $globalData);
		}
	}
}
