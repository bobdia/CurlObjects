<?php

class CurlReq {
	/* Options */
	public $url; // URL to connect to

	// array of curl options
	public $options = array(
		// CURLOPT_TIMEOUT => 3600,
		// CURLOPT_CONNECTTIMEOUT => 60,
		// CURLOPT_DNS_CACHE_TIMEOUT => 120 (in seconds, default)
	); 

	public $keep = false; // set to true to keep curl handle alive
	
	public $singleFail = false; // curlerror, emptybody will trigget fail()
	
	// callback registry for events
	// array of each event can contain: 0,array($obj,$method),string
	// if 0, calls a method of the req named after the event, equivalent to array($this, $event_type)
	// if array, calls the $method on the $obj
	// if string, calls the function named in the string
	public $events = array(
			'before' => array(0),
			'curlerror' => array(0),
			'parse' => array(0),
			'decide' => array(0),
			'success' => array(0),
			'emptybody' => array(0),
			'after' => array(0),
			'fail' => array(0)
			);

	public $logEvents = false; // enable/disable event logging
	public $ignoreEvents = array(); // array of events to ignore, example: array('emptybody'=> 0)
	public $maxExecCount = 200; // max exec() cycles, prevents infinite loops
	public $execCount = 0; // current exec count
	
	/* Data */
	
	public $body; // response  body
	public $error = array();	// errors (includes cURL errors)
	public $eventLog = array(); // event log
	
	
	public $_id; // index of the request in the curlbase object's $requests array
	public $_handle;
	
	public function __construct($url, $options=null) {
		$this->url = $url;

		if(is_array($options)) {
			$this->options = $this->options + $options;
		}
		
	}

	// set a proxy
	public function proxy($ip, $port, $userpwd=false, $tunnel=false, $socks=false) {
		$this->options[CURLOPT_PROXY] = $ip;
		$this->options[CURLOPT_PROXYPORT] = $port;
		
		if($userpwd) {
			$this->options[CURLOPT_PROXYUSERPWD] = $userpwd;
		}
		
		if($tunnel) {
			$this->options[CURLOPT_HTTPPROXYTUNNEL] = 1;
		}
		if($socks) {
			//CURLPROXY_SOCKS5
			$this->options[CURLOPT_PROXYTYPE] = $socks;
		}
	}
	
	// set local network interface to use
	public function ip($str) {
		$this->options[CURLOPT_INTERFACE] = $str;
	}

	// attach a callback to an event
	public function attach($event,$callback,$append=1) {
		if($append === 1) {
			// default, append to the end of the array
			$this->events[$event][] = $callback;
		} elseif($append === 0) {
			// replace existing callback array
			$this->events[$event] = array($callback);
		} elseif($append === -1) {
			// in the beginning of the array
			$this->events[$event] = array_unshift($this->eventReg[$event], $callback);
		}
	}
	
	// trigger an event
	public function event($type,$args=null) {
		if(array_key_exists($type, $this->ignoreEvents)) return; // event ignored

		if($this->logEvents) $this->eventLog[] = $type; // event logging

		if(array_key_exists($type, $this->events)) {

			foreach($this->events[$type] as $fun) {
				// if the callback function is named after the event
				if($fun === 0) {
					$fun = array($this, $type);
				}
				
				if(is_callable($fun)) {
				
					if((is_array($fun) && !($fun[0] instanceof $this)) || is_string($fun)) {
						if(is_array($args)) {
							array_unshift($args,$this);
						} else {
							$args = array($this);
						}
					}
					
					if($args === null) {
						call_user_func($fun);
					} elseif(is_array($args)) {
						call_user_func_array($fun, $args);
					}
				}
			}
		}
	}
	
	public function initHandle() {
		if(($handle = curl_init()) !== false) {
			return $handle;
		} else {
			$this->event('curlerror', array(999, 'Failed to initialize handle'));
			return false;
		}
	}
	
	// set the curlOptions
	public function setOptions($defaults=null) {
		if(is_array($defaults)) {
			$this->options = $defaults + $this->options;
		}
	
		$this->options[CURLOPT_URL] = $this->url;
		
		return $this->options;
	}
	
	public function isReturnTransfer() {
		return (isset($this->options[CURLOPT_RETURNTRANSFER]) && $this->options[CURLOPT_RETURNTRANSFER] == true);
	}
	
	public function exec() {
		
		// before
		$this->event('before');
		
		// init
		if(!is_resource($this->_handle)) {
			$this->_handle = $this->initHandle();
		}
		
		if($this->_handle !== false) {
		
			// set options
			$this->setOptions();
			
			if(curl_setopt_array($this->_handle, $this->options) === false) {
				$this->event('curlerror', array(999, 'Failed to set options'));
			} else {
			
				//execute
				$response = curl_exec($this->_handle);
				$this->execCount++;
				
				// no curl error
				if ( ($ern = curl_errno($this->_handle)) === 0 ) {

					// get info
					$info = curl_getinfo($this->_handle);
					
					// trigger parse event
					$this->event('parse', array($response, $info));
					
					$this->event('decide');
					
				} else {
					// some curl error
					$this->event('curlerror', array($ern, curl_error($this->_handle)));
				}
			}
		}
		
		// after
		$this->event('after');
		
		// prevent infinite loops
		
		if($this->execCount > $this->maxExecCount) {
			$this->event('maxexec', array($this->execCount));
		}
		
		if(!$this->keep) {
			if(is_resource($this->_handle)) { curl_close($this->_handle); }
			$this->_handle = false;
		} else {
			// we are keeping the handle alive
			$this->exec();
		}
	}
	
	public function logError($n,$msg) {
		$this->error[] = array($n,$msg);
	}
	
	protected function decide() {
		if($this->isReturnTransfer() && empty($this->body)) {
			$this->event('emptybody');
		} else {
			$this->event('success');
		}
	}
	
	// Before handle is initialised and options set
	protected function before() {}
	
	// Before handle is closed and removed from the pool and request set as inactive
	protected function after() {}
	
	// If no curl error, no empty
	protected function success() {}

	protected function curlerror($n,$msg) {
		$this->logError($n, $msg);
		$this->keep = false;
		
		if($this->singleFail) {
			$this->event('fail', array('curlerror'));
		}
	}
	
	// If no curl error and response body is empty
	protected function emptybody() {
		$this->logError(1005, 'Response body is empty.');
		$this->keep = false;
		
		if($this->singleFail) {
			$this->event('fail', array('emptybody'));
		}
	}
	
	protected function maxexec($execNum) {
		$this->logError(1003, "maxExecCount reached: $execNum");
		$this->keep = false;
		
		if($this->singleFail) {
			$this->event('fail', array('maxexec'));
		}
	}
	
	protected function fail($errorType) {}
	
	// If no curl error happens
	protected function parse($response, $info) {
		$this->info = $info;
		
		if($this->isReturnTransfer()) {
			$this->body = $response;
		}
	}
}
?>