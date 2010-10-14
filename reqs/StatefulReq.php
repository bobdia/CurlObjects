<?php

class StatefulReq extends HttpReq {


	protected $states = array();
	protected $state = null;
	protected $lastState = null;

	protected function after() {
		if($this->keep)
		$this->nextState();
	}

	public function addOpt($opt,$val,$state=null) {
		if(is_null($state))
			$state = $this->state;

		$this->states[$state]['opts'][$opt] = $val;

	}

	public function addArg($nam,$val,$state=null) {
		if(is_null($state))
			$state = $this->state;

		$this->states[$state]['args'][$nam] = $val;
	}

	public function registerState($state, $args=null){
		if(is_string($args)) {
			$this->states[$state]['url'] = $args;
		} elseif(is_array($args)) {
			$this->states[$state] = $args;
		} else {
			$this->states[$state] = array();
		}
		if(is_null($this->state)) $this->state = key($this->states);
	}

	public function setState($state){
		if(isset($this->states[$state])) {
			$this->state = $state;
			reset($this->states);
			while($this->state != key($this->states)){
				next($this->states);
			}
			return true;
		}
		return false;
	}

	public function nextState(){
		$this->lastState = $this->state;
		next($this->states);
		if($this->state = key($this->states))
			return $this->state;
		else
			return false;
	}
	
	public function attach($event,$callback,$position=1) {
		// otherwise the $position param won't work for stateEvent 
		if(!isset($this->eventReg[$event])) {
			$this->eventReg[$event] = array(0);
		}
		
		if($append === 1) {
			// default, append to the end of the array
			$this->eventReg[$event][] = $callback;
		} elseif($append === 0) {
			// replace existing callback array
			$this->eventReg[$event] = array($callback);
		} elseif($append === -1) {
			// in the beginning of the array
			$this->eventReg[$event] = array_unshift($this->eventReg[$event], $callback);
		}
	}
	
	public function event($type, $args = null){
		parent::event($type, $args);

		$stateEvent = $this->state . ucfirst($type);
		if(method_exists($this,$stateEvent)) {
			if(!isset($this->eventReg[$stateEvent])) {
				$this->eventReg[$stateEvent] = array(0);
			}

		}

		if(isset($this->eventReg[$stateEvent]) && is_array($this->eventReg[$stateEvent])) {
			parent::event($stateEvent, $args);
		}
	}

	public function setOptions($defaults=null) {
		if(!is_array($defaults)) {
			$defaults = array();
		}
	
		if(isset($this->states[$this->state]['url']))
			$this->url = $this->states[$this->state]['url'];
		if(isset($this->states[$this->state]['args']))
			$this->args = $this->states[$this->state]['args'];
		if(isset($this->states[$this->state]['cookies']))
			$this->cookies($this->states[$this->state]['cookies']);
		if(isset($this->states[$this->state]['cookies']))
			$this->method = $this->states[$this->state]['method'];

		
		if(isset($this->states[$this->state]['options'])) {
			return $defaults + parent::setOptions() + $this->states[$this->state]['options'];
		} else {
			return $defaults + parent::setOptions();
		}
	}
}
?>