<?php


class ReqFactory {

	public $multi;
 	public $exe;
	public $objects;

 	public function __construct($args=null, $multi=1, $exe=0) {
 		if($args) { $this->args = $args;}
		$this->multi = $multi;
		$this->exe = $exe;
 	}

	public function make($args,$filter='all',$type='objects') {

		$chosenObjects = $this->choose($filter, $type);
		$req = array();

		foreach($chosenObjects as $k) {
			$a = $this->$type[$k];

			$className = isset($a['class'])?$a['class']:$k;

			$constructor = 'make'.$k;

			if(method_exists($this,$constructor)) {
				$reqs[$k] = $this->$constructor($args);
			} else {
				$reflector = new ReflectionClass($className);
				$reqs[$k] =(object) $reflector->newInstanceArgs($args);
			}

		}
		return $this->exe?$this->exe($reqs):$reqs;
	}


	// Intersects $objects array with ReqFactory::$type array
	// Returns chosen $keys.

	protected function choose($filter,$type='objects') {
		if($filter == 'all') {
			$s = array_keys($this->$type);
		} else {
			if(!is_array($filter))
				$filter = array($filter);
			$s = array_intersect(array_keys($this->$type), $filter);
		}
		return $s;
	}

	protected function exe($objects) {
		if($this->multi) {
			$c = new CurlBase;
			$c->addArr($objects);
			$c->perform();
			return $c->requests;
		} else {
			CurlExec::sendArr($objects);
			return $objects;
		}


	}

}
?>