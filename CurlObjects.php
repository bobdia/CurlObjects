<?php
/**************************
Copyright (c) 2010, Robert Diaes, http://disattention.com/
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
    * Neither the name of Robert Diaes or disattention.com,  nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
**************************/


class CurlBase {

	// If you are having problems with curl_multi or you just don't need concurrent requests, change to false and it will work with
	// non-multi curl. You will still be able to use perform() to execute multiple requests (but they won't be executed concurrently).
	public $multi = true;
	// Max simultaneous requests, if there are more in the request pool it  chunks the array
	public $maxChunk = 25;
	// Delay between requests in seconds, only works with $multi set to false.
	public $delaySingle = 0;
	// Delay between chunks in seconds, only works with $multi set to true.
	public $delayChunks = 0;
	// Function to be run between chunks, called with $this (CurlBase) as it's only argument
	public $callbackBetweenChunks = null;


	// Request pool
	public $requests = array();

	
	// DEFAULT CURL OPTIONS
	// Duplicate options will be overriden by their value in CurlRequest::$options
	public $defaultOptions = array(
				//CURLOPT_RETURNTRANSFER => true
	);
	
	public $defaultAttach = array(
		//array($event, $callback, $position),
		//array($event, $callback, $position)
	);
				
				
				
	// Curl Multi errors
	public $multiErrors = array();


	/*
	** Internal stuff
	*/

	// Multi curl handle
	protected $mh;
	// Request count
	protected $rCount = 0;
	// Active requests
	protected $active = array();

/*
** Constructor
*/
	public function __construct($requests=null,$options=null) {
		set_time_limit($this->maxTime);

		if($requests)
			$this->addArr($requests);
		if($options)
			$this->defaultOptions += $options;

	}


/*
** Main methods
*/
	
	// add a CurlRequest
	public function add(&$request, $id=null) {
		if(!isset($id)) { $id = $this->rCount; }
		
		$this->requests[$id] = $request;
		$this->requests[$id]->_id = $id;
		$this->setActive($id, TRUE);
		$this->rCount++;
		return $id;
	}
	
	// alias for addArr()
	public function addArray(&$requests) {
		return $this->addArr($requests);
	}
	
	// add array of CurlRequests
	public function addArr(&$requests) {
		foreach($requests as $i => &$request) {
			if(is_int($i)) { $i = $this->rCount; }
			$ids[]=$i;
			$this->add($request, $i);
		}
		return $ids;
	}
	
	// execute added CurlRequests
	public function perform() {
		// check if there are active requests, must have been added after the last perform, other reqs in $this->requests won't count
		if(($k = count($this->active)) > 0 ) {
			//If there's only one request drop down to single mode
			if($k == 1)
				$this->multi = false;
			// Initialise the multi-handle if we are in multi mode
			if($this->multi) {
				if(!($this->mh = curl_multi_init())) {
					$this->multiErrors[] = "Failed to initialize multi handle";
					return;
				}
			}
			// Chunk and perform() once for each chunk, if we have more active requests than allowed to perform simultaneously
			if($this->multi && $this->maxChunk < $k)
			{
				$chunks = array_chunk($this->active, $this->maxChunk, true);

				foreach($chunks as $chunk) {
					
					// assign current chunk as active
					$this->active = $chunk;
					
					// this will only loop when requests use $keep=true
					do {
						// perform requests in the pool
						$this->performMulti();
					} while(count($this->active)>0);

					// Callback for cleanup between chunks
					if($this->callbackBetweenChunks) {
						// pass a reference to $this CurlBase as the only argument
						call_user_func_array($this->callbackBetweenChunks, array($this));
					}

					// delay if needed
					if($this->delayBetweenChunks > 0) {
						sleep($this->delayBetweenChunks);
					}
				}
			}
			// If we only have one chunk
			else {
				// this will only loop when requests use $keep=true
				do {

					// perform requests in the active pool
					$this->performMulti();

				} while(count($this->active)>0);
			}

			// Close the multi handle
			if($this->multi) {
				curl_multi_close($this->mh);
			}
		}
	}

/******
** Internal methods
******/

	protected function addHandle($k) {
		if(($n = curl_multi_add_handle($this->mh,$this->active[$k])) > 0) {
			
			$this->setActive($k, FALSE);
			$this->requests[$k]->event('curlerror', array(999, 'Failed to add to multihandle'));
		}
	}

	protected function removeHandle($k) {
		if(($n = curl_multi_remove_handle($this->mh,$this->active[$k])) === false) {
			$this->setActive($k, FALSE);
			$this->requests[$k]->event('curlerror', array(999, 'Failed to remove from multihandle'));
		}
	}

	protected function setOptions($k) {
		$req = $this->requests[$k];
		
		if(curl_setopt_array($this->active[$k], $req->setOptions($this->defaultOptions)) === false) {
			$req->event('curlerror', array(999, 'Failed to set options'));
			$this->setActive($k, FALSE);
			return false;
		} else {
			return true;
		}
	}
	
	protected function setActive($k, $active) {
		if($active) {
			$this->active[$k] = $this->requests[$k]->_handle;
		} else {
			unset($this->active[$k]);
		}
	}
	
	protected function prepareReqs() {
		foreach(array_keys($this->active) as $k) {
			$req = $this->requests[$k];
			
			foreach($this->defaultAttach as $at) {
				$req->attach($at[0], $at[1], $at[2]);
			}
			
			$req->event('before');

			if($req->execCount == 0) {
				if(($handle = curl_init()) !== false) {
					$req->_handle = $handle;
					
					$this->setActive($k, TRUE);
					
					if($this->setOptions($k)) { 
						$this->addHandle($k); 
					}
				} else {
					$this->setActive($k, FALSE);
				}	
			} else {
				if($this->setOptions($k)) {
					$this->addHandle($k);
				}
			}
		}
	
	}

	protected function performMulti() {
		if($this->multi) {
		
			$this->prepareReqs();
	
			// Start performing the requests
			$activeConnections = null;
			do {
				$mrc = curl_multi_exec($this->mh, $activeConnections);
			} while($mrc == CURLM_CALL_MULTI_PERFORM);
			
			while ($activeConnections > 0 && $mrc == CURLM_OK) {
				
				// Wait for network
				if (curl_multi_select($this->mh) != -1) {
					
					// pull in any new data, or at least handle timeouts
					do { 
						$mrc = curl_multi_exec($this->mh, $activeConnections);
					} while( $mrc == CURLM_CALL_MULTI_PERFORM);
				}
			}

			if ($mrc !== CURLM_OK) {
				$this->multiErrors[] = "Curl multi read error: $mrc\n";
				// return false;
			}
		}

		foreach(array_keys($this->active) as $k) {
			$req = $this->requests[$k];
			
			if(!$this->multi) {
				if($this->delayBetweenSingle > 0) {
					sleep($this->delayBetweenSingle);
				}
				
				$req->exec();
			}
			
			if($this->multi) {
				if (($ern = curl_errno($this->active[$k])) === 0 ) {
					$response = curl_multi_getcontent($this->active[$k]);
					
					$info = curl_getinfo($this->active[$k]);
					$req->execCount++;
					$req->event('parse', array($response,$info));
					$req->event('decide');
				} else {
					$msg = curl_error($this->active[$k]);
					$req->event('curlerror', array($ern, $msg));
				}
			
			
				$this->removeHandle($k);
				$req->event('after');
				
				if($req->execCount > $req->maxExecCount) {
					$req->event('maxexec', array($req->execCount));
				}
			}
			
			if(!$req->keep) {
				if(is_resource($this->active[$k])) { 
					curl_close($this->active[$k]);
				}
				$req->_handle = false;
				$this->setActive($k, FALSE);
			}
		}
	}
}




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
			'after' => array(0)
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
	public function proxy($ip, $port, $userpwd=null,$tunnel=false,$socks=CURLPROXY_SOCKS5) {
		$this->options[CURLOPT_PROXY] = $ip;
		$this->options[CURLOPT_PROXYPORT] = $port;
		
		if($userpwd) {
			$this->options[CURLOPT_PROXYUSERPWD] = $userpwd;
		}
		
		if($tunnel) {
			$this->options[CURLOPT_HTTPPROXYTUNNEL] = 1;
		}
		
		$this->options[CURLOPT_PROXYTYPE] = $socks;
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
		if($this->execCount == 0) {
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





class HttpReq extends CurlReq {
	/* Options */

	public $options = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_HEADER => 1,
			CURLOPT_AUTOREFERER => 1,
			CURLOPT_FOLLOWLOCATION => 1,
			CURLOPT_MAXREDIRS => 30,
			CURLOPT_ENCODING => '',
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0
			/*CURLOPT_HTTP_VERSION  	 
				CURL_HTTP_VERSION_NONE  (default, lets CURL decide which version to use), 
				CURL_HTTP_VERSION_1_0  (forces HTTP/1.0), or CURL_HTTP_VERSION_1_1  (forces HTTP/1.1).
			*/
			);
	
	
	public $method = 'get'; // HTTP method to use
	public $args; // assoc. array of HTTP parameters, used for both GET and POST
	// Passing an array to CURLOPT_POSTFIELDS will encode the data as multipart/form-data, while passing a URL-encoded string will encode the data as application/x-www-form-urlencoded. 
	public $stringPost = true;
	
	public $rawUrlEncode = false; // whether to use urlencode() or rawurlencode()
	public $argsSeparator; // default is '&'
	public $argsArraySeparator; // default is '[]'


	public $parseHeaders = true; // set to true to parse headers
	public $parseCookies = true; // set to true to parse cookies, needs an assoc array in $headers
	public $parseDOM = false; // set to true to parse the DOM,
	public $parseForms = false; // set to true to parse html forms,

	/* Data */
	public $cookies; // cookies assoc arrays
	public $headers; // headers assoc arrays
	public $dom; // DOM object | http://simplehtmldom.sourceforge.net/
	public $forms; // parsed HTML forms

	public $body; // body string
	public $head; // headers string

	public $status; // HTTP status code
	public $info; // cURL handle info array
	
	public $cookieFile; // full path to cookie file

	public $events = array(
			'before' => array(0),
			'curlerror' => array(0),
			'parse' => array(0),
			'decide' => array(0),
			'success' => array(0),
			'emptybody' => array(0),
			'httperror' => array(0),
			'after' => array(0)
			);

	public function __construct($url, $options=null) {
		parent::__construct($url,$options);
	}
	
	/* Helpers */
	
	// create a cookie file
	public function cookieFile($dir=null,$prefix=null) {
		if(!$dir) { $dir = getcwd(); }
		if(!$prefix) { $prefix = get_class(); }

		$this->cookieFile = tempnam($dir,$prefix);
		return $this->cookieFile;
	}
	
	// sets a cookie from string
	public function cookie($str) {
		$this->options[CURLOPT_COOKIE] = $str;
	}
	
	// sets a cookie from $this->cookies array
	public function cookies($cookieArr,$deep=false) {
		$merged = '';
		if($deep) {
			foreach($cookieArr as $arr) {
				foreach($arr as $k=>$v) {
					$merged .= $k.'='.$v.'; ';
				}
			}
		} else {
			foreach($cookieArr as $k=>$v) {
				$merged .= $k.'='.$v.'; ';
			}
		}
		$this->options[CURLOPT_COOKIE] = rtrim($merged);
	}
	
	// sets HTTP auth
	public function auth($user, $pass, $type=null) {
		$this->options[CURLOPT_USERPWD] = $user .':'. $pass;
		if($type) {
			$this->options[CURLOPT_HTTPAUTH] = $type;
		} else {
			$this->options[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
		}
	}

	// sets useragent
	public function ua($str) {
		$this->options[CURLOPT_USERAGENT] = $str;
	}

	// sets referer
	public function ref($str) {
		$this->options[CURLOPT_REFERER] = $str;
	}
	
	// follows a link, requires DOM to be parsed
	public function follow($query,$index=0,$attr='href') {
		if($this->dom) {
			$e = $this->dom->find($query,$index);
			$this->url = $e->$attr;
			$this->keep = true;
		} else {
			$this->logError(1009,'Must parse DOM to use follow()');
		}
	}
	
	/*
	TODO
	public function submit($form) {
		if($this->forms) {}
	}
	*/

	/* Callbacks */
	
	// If no curl error and http status code >= 400
	protected function httperror($code) {
		$this->logError(1001, "HTTP error: $code");
		$this->keep = false;

		if($this->singleFail) {
			$this->event('fail', array('emptybody'));
		}
	}

	// If no curl error happens
	protected function parse($response, $info) {
		$this->info = $info;
		$this->status = $info['http_code'];
		
		$k = $this->isReturnTransfer();
		
		if(isset($this->options[CURLOPT_HEADER]) && $k) {
			// possibly dangerous
			$this->head = substr($response,0,$info['header_size']);
			$this->body = substr($response,$info['header_size']);
		} elseif($k) {
			$this->body = $response;
		}

		if($this->head && $this->parseHeaders) {
			$this->headers = CurlUtil::headers($this->head);
		}
		if($this->headers && $this->parseCookies) {
			$this->cookies = CurlUtil::cookies($this->headers);
		}


	}
	
	protected function decide() {
		if($this->status >= 400) {
			$this->event('httperror', array($this->status));
		} elseif($this->isReturnTransfer() && empty($this->body)) {
			$this->event('emptybody');
		} else {
			if($this->parseDOM && $this->body) {
				$this->dom = CurlUtil::parseDOM($this->body);
			}
			if($this->parseForms && $this->body) {
				$this->forms = CurlUtil::parseForms($this->body);
			}
			$this->event('success');
		}	
	
	}
	
	// set curl options and do magic
	public function setOptions($defaults=null) {
		if(is_array($defaults)) {
			$this->options = $defaults + $this->options;
		}
		
		if($this->method == 'get') {
			
			$this->options[CURLOPT_HTTPGET] = 1;
			
			if(isset($this->options[CURLOPT_POST])) {
				unset($this->options[CURLOPT_POST]);
			}
			
			if(isset($this->options[CURLOPT_POSTFIELDS])) {
				unset($this->options[CURLOPT_POSTFIELDS]);
			}
			
			if($this->args) {
				// possibly dangerous
				// this could introduce problems if you are making a very special GET url
				$this->cleanGetArgs();
				
				$this->url = $this->url.'?'.CurlUtil::vars($this->args, $this->rawUrlEncode, $this->argsSeparator, $this->argsArraySeparator);
			}
			
		} elseif($this->method == 'post') {
			$this->options[CURLOPT_POST] = 1;
			
			if(isset($this->options[CURLOPT_HTTPGET])) {
				unset($this->options[CURLOPT_HTTPGET]);
			}
			
			if($this->args) {
				if($this->stringPost) {
					$this->options[CURLOPT_POSTFIELDS] = CurlUtil::vars($this->args, $this->rawUrlEncode, $this->argsSeparator, $this->argsArraySeparator );
				} else {
					$this->options = $this->args;
				}
			}
			
		}

		$this->options[CURLOPT_URL] = $this->url;

		if($this->cookieFile) {
			// possibly dangerous if cookiejar is used by many reqs, might overwrite newer data
			$this->options[CURLOPT_COOKIEFILE] = $this->cookieFile;
			$this->options[CURLOPT_COOKIEJAR] = $this->cookieFile;
		}
		
		return $this->options;
	}
	
	protected function cleanGetArgs() {
		if(($p = strpos($this->url, '?')) !== false) {
			$this->url = substr($this->url, 0, $p);
		}
	}
}




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




class CurlUtil {
public static function profile(&$request, $options) {
		if(isset($options['headers']) && is_array($options['headers'])) {
			$headers = $options['headers'];
		} else {
			$headers = array();
		}
		
		if(isset($options['ip'])) $request->ip($options['ip']);
		if(isset($options['proxy'])) $request->proxy($options['proxy']);
		if(isset($options['ua'])) $request->ua($options['ua']);
		if(isset($options['cookieFile'])) $request->cookieFile = $options['cookieFile'];
		if(isset($options['lang'])) {

		}
		if(isset($options['accept'])) {

		}
		if(isset($options['ajax'])) {
			$headers[] = 'X-Requested-With: XMLHttpRequest';
		}
		$request->options[CURLOPT_HTTPHEADERS] = $options['headers'];
		return $request;
}


public static function vars($vars,$raw=false,$sep='&',$arraySuffix='[]') {
	$str = '';
	foreach( $vars as $k => $v) {
		if(is_array($v)) {
			foreach($v as $vi) {
				$str .= (($raw)?rawurlencode($k):urlencode($k)).$arraySuffix.'='.(($raw)?rawurlencode($vi):urlencode($vi)).$sep;
			}
		} else {
			$str .= (($raw)?rawurlencode($k):urlencode($k)).'='.(($raw)?rawurlencode($v):urlencode($v)).$sep;
		}
	}
	return substr($str, 0, -1);
}

public static function varsToArray($str){
	$arr = array();
	parse_str($str, $arr);
	return $arr;
}

// String of headers, also works with multiple header sets in a row
public static function headers($str) {
	//$str = str_replace("\r\n","\n",$str);
	$h = array_filter(explode("\r\n",$str));
	$headers = array();
	$c = -1;
	foreach($h as $i) {
		if(($p = strpos($i, ':')) !== false) {
			$type = trim(strtolower(substr($i,0,$p)));
			$headers[$c][$type][] = trim(substr($i,$p+1));
		} else {
			$c++;
			$parts  = explode(' ', $i,3);
			$headers[$c]['http'] = array(
				'version' => $parts[0],
				'status' => $parts[1],
				'string' => $parts[2]
				); 
		}
	}
	return $headers;
}

// Iterates through array from CurlUtil::headers
// $other=true gives you 'domain', 'expires', 'path', 'secure', 'comment' in the return arrays
public static function cookies($headers,$other=false) {
	$cookies = array();
	foreach($headers as $i => $headers) {
		if(!isset($headers['set-cookie']))
			continue;
		foreach($headers['set-cookie'] as $str) {
			if( strpos($str,';') === false) {
				$c = explode('=',$str,2);
				$c[0] = trim($c[0]);
				$c[1] = trim($c[1]);
				$cookies[$i][$c[0]] = $c[1];
			} else {
				$cookieparts = explode( ';', $str );

				foreach($cookieparts as $data ) {
					$c = explode( '=', $data, 2);
					$c[0] = trim($c[0]);
					$c[1] = trim($c[1]);
					if(in_array( $c[0], array('domain', 'expires', 'path', 'secure', 'comment'))) {
						if($other)  {
							switch($c[0]) {
							case 'expires':
								$c[1] = strtotime($c[1]);
								break;
							case 'secure':
								$c[1] = true;
								break;
							}
							$cookies[$i][$c[0]] = $c[1];
						}
					} else {
						$cookies[$i][$c[0]] = $c[1];
					}
				}
			}
		}
	}
	return $cookies;		
}

public static $ifconfigRegex = '@addr:(?=([0-9.]+))(?!(?:10\.|127\.|172\.[1-3][6-9_0]\.|192\.|169\.254\.[0-9.]+))@';

public static function discoverInterfaces() {
	$a = shell_exec( 'ifconfig -a' );
	preg_match_all( self::$ifconfigRegex, $a, $m);
	return $m[1];
}

public static function parseDOM($obj){
	//$obj can be html or HttpRequest Object
	if($obj instanceof HttpRequest) $str = (string) $obj->body;
	else $str = $obj;
	$dom = new DOMDocument;
	return $dom->loadHTML($str);
}
}


/*******************************************************************************
Version: 1.11 ($Rev: 175 $)
Website: http://sourceforge.net/projects/simplehtmldom/
Author: S.C. Chen <me578022@gmail.com>
Acknowledge: Jose Solorzano (https://sourceforge.net/projects/php-html/)
Contributions by:
    Yousuke Kumakura (Attribute filters)
    Vadim Voituk (Negative indexes supports of "find" method)
    Antcs (Constructor with automatically load contents either text or file/url)
Licensed under The MIT License
Redistributions of files must retain the above copyright notice.
*******************************************************************************/

define('HDOM_TYPE_ELEMENT', 1);
define('HDOM_TYPE_COMMENT', 2);
define('HDOM_TYPE_TEXT',    3);
define('HDOM_TYPE_ENDTAG',  4);
define('HDOM_TYPE_ROOT',    5);
define('HDOM_TYPE_UNKNOWN', 6);
define('HDOM_QUOTE_DOUBLE', 0);
define('HDOM_QUOTE_SINGLE', 1);
define('HDOM_QUOTE_NO',     3);
define('HDOM_INFO_BEGIN',   0);
define('HDOM_INFO_END',     1);
define('HDOM_INFO_QUOTE',   2);
define('HDOM_INFO_SPACE',   3);
define('HDOM_INFO_TEXT',    4);
define('HDOM_INFO_INNER',   5);
define('HDOM_INFO_OUTER',   6);
define('HDOM_INFO_ENDSPACE',7);

// helper functions
// -----------------------------------------------------------------------------
// get html dom form file
function file_get_html() {
    $dom = new simple_html_dom;
    $args = func_get_args();
    $dom->load(call_user_func_array('file_get_contents', $args), true);
    return $dom;
}

// get html dom form string
function str_get_html($str, $lowercase=true) {
    $dom = new simple_html_dom;
    $dom->load($str, $lowercase);
    return $dom;
}

// dump html dom tree
function dump_html_tree($node, $show_attr=true, $deep=0) {
    $lead = str_repeat('    ', $deep);
    echo $lead.$node->tag;
    if ($show_attr && count($node->attr)>0) {
        echo '(';
        foreach($node->attr as $k=>$v)
            echo "[$k]=>\"".$node->$k.'", ';
        echo ')';
    }
    echo "\n";

    foreach($node->nodes as $c)
        dump_html_tree($c, $show_attr, $deep+1);
}

// get dom form file (deprecated)
function file_get_dom() {
    $dom = new simple_html_dom;
    $args = func_get_args();
    $dom->load(call_user_func_array('file_get_contents', $args), true);
    return $dom;
}

// get dom form string (deprecated)
function str_get_dom($str, $lowercase=true) {
    $dom = new simple_html_dom;
    $dom->load($str, $lowercase);
    return $dom;
}

// simple html dom node
// -----------------------------------------------------------------------------
class simple_html_dom_node {
    public $nodetype = HDOM_TYPE_TEXT;
    public $tag = 'text';
    public $attr = array();
    public $children = array();
    public $nodes = array();
    public $parent = null;
    public $_ = array();
    private $dom = null;

    function __construct($dom) {
        $this->dom = $dom;
        $dom->nodes[] = $this;
    }

    function __destruct() {
        $this->clear();
    }

    function __toString() {
        return $this->outertext();
    }

    // clean up memory due to php5 circular references memory leak...
    function clear() {
        $this->dom = null;
        $this->nodes = null;
        $this->parent = null;
        $this->children = null;
    }
    
    // dump node's tree
    function dump($show_attr=true) {
        dump_html_tree($this, $show_attr);
    }

    // returns the parent of node
    function parent() {
        return $this->parent;
    }

    // returns children of node
    function children($idx=-1) {
        if ($idx===-1) return $this->children;
        if (isset($this->children[$idx])) return $this->children[$idx];
        return null;
    }

    // returns the first child of node
    function first_child() {
        if (count($this->children)>0) return $this->children[0];
        return null;
    }

    // returns the last child of node
    function last_child() {
        if (($count=count($this->children))>0) return $this->children[$count-1];
        return null;
    }

    // returns the next sibling of node    
    function next_sibling() {
        if ($this->parent===null) return null;
        $idx = 0;
        $count = count($this->parent->children);
        while ($idx<$count && $this!==$this->parent->children[$idx])
            ++$idx;
        if (++$idx>=$count) return null;
        return $this->parent->children[$idx];
    }

    // returns the previous sibling of node
    function prev_sibling() {
        if ($this->parent===null) return null;
        $idx = 0;
        $count = count($this->parent->children);
        while ($idx<$count && $this!==$this->parent->children[$idx])
            ++$idx;
        if (--$idx<0) return null;
        return $this->parent->children[$idx];
    }

    // get dom node's inner html
    function innertext() {
        if (isset($this->_[HDOM_INFO_INNER])) return $this->_[HDOM_INFO_INNER];
        if (isset($this->_[HDOM_INFO_TEXT])) return $this->dom->restore_noise($this->_[HDOM_INFO_TEXT]);

        $ret = '';
        foreach($this->nodes as $n)
            $ret .= $n->outertext();
        return $ret;
    }

    // get dom node's outer text (with tag)
    function outertext() {
        if ($this->tag==='root') return $this->innertext();

        // trigger callback
        if ($this->dom->callback!==null)
            call_user_func_array($this->dom->callback, array($this));

        if (isset($this->_[HDOM_INFO_OUTER])) return $this->_[HDOM_INFO_OUTER];
        if (isset($this->_[HDOM_INFO_TEXT])) return $this->dom->restore_noise($this->_[HDOM_INFO_TEXT]);

        // render begin tag
        $ret = $this->dom->nodes[$this->_[HDOM_INFO_BEGIN]]->makeup();

        // render inner text
        if (isset($this->_[HDOM_INFO_INNER]))
            $ret .= $this->_[HDOM_INFO_INNER];
        else {
            foreach($this->nodes as $n)
                $ret .= $n->outertext();
        }

        // render end tag
        if(isset($this->_[HDOM_INFO_END]) && $this->_[HDOM_INFO_END]!=0)
            $ret .= '</'.$this->tag.'>';
        return $ret;
    }

    // get dom node's plain text
    function text() {
        if (isset($this->_[HDOM_INFO_INNER])) return $this->_[HDOM_INFO_INNER];
        switch ($this->nodetype) {
            case HDOM_TYPE_TEXT: return $this->dom->restore_noise($this->_[HDOM_INFO_TEXT]);
            case HDOM_TYPE_COMMENT: return '';
            case HDOM_TYPE_UNKNOWN: return '';
        }
        if (strcasecmp($this->tag, 'script')===0) return '';
        if (strcasecmp($this->tag, 'style')===0) return '';

        $ret = '';
        foreach($this->nodes as $n)
            $ret .= $n->text();
        return $ret;
    }
    
    function xmltext() {
        $ret = $this->innertext();
        $ret = str_ireplace('<![CDATA[', '', $ret);
        $ret = str_replace(']]>', '', $ret);
        return $ret;
    }

    // build node's text with tag
    function makeup() {
        // text, comment, unknown
        if (isset($this->_[HDOM_INFO_TEXT])) return $this->dom->restore_noise($this->_[HDOM_INFO_TEXT]);

        $ret = '<'.$this->tag;
        $i = -1;

        foreach($this->attr as $key=>$val) {
            ++$i;

            // skip removed attribute
            if ($val===null || $val===false)
                continue;

            $ret .= $this->_[HDOM_INFO_SPACE][$i][0];
            //no value attr: nowrap, checked selected...
            if ($val===true)
                $ret .= $key;
            else {
                switch($this->_[HDOM_INFO_QUOTE][$i]) {
                    case HDOM_QUOTE_DOUBLE: $quote = '"'; break;
                    case HDOM_QUOTE_SINGLE: $quote = '\''; break;
                    default: $quote = '';
                }
                $ret .= $key.$this->_[HDOM_INFO_SPACE][$i][1].'='.$this->_[HDOM_INFO_SPACE][$i][2].$quote.$val.$quote;
            }
        }
        $ret = $this->dom->restore_noise($ret);
        return $ret . $this->_[HDOM_INFO_ENDSPACE] . '>';
    }

    // find elements by css selector
    function find($selector, $idx=null) {
        $selectors = $this->parse_selector($selector);
        if (($count=count($selectors))===0) return array();
        $found_keys = array();

        // find each selector
        for ($c=0; $c<$count; ++$c) {
            if (($levle=count($selectors[0]))===0) return array();
            if (!isset($this->_[HDOM_INFO_BEGIN])) return array();

            $head = array($this->_[HDOM_INFO_BEGIN]=>1);

            // handle descendant selectors, no recursive!
            for ($l=0; $l<$levle; ++$l) {
                $ret = array();
                foreach($head as $k=>$v) {
                    $n = ($k===-1) ? $this->dom->root : $this->dom->nodes[$k];
                    $n->seek($selectors[$c][$l], $ret);
                }
                $head = $ret;
            }

            foreach($head as $k=>$v) {
                if (!isset($found_keys[$k]))
                    $found_keys[$k] = 1;
            }
        }

        // sort keys
        ksort($found_keys);

        $found = array();
        foreach($found_keys as $k=>$v)
            $found[] = $this->dom->nodes[$k];

        // return nth-element or array
        if (is_null($idx)) return $found;
		else if ($idx<0) $idx = count($found) + $idx;
        return (isset($found[$idx])) ? $found[$idx] : null;
    }

    // seek for given conditions
    protected function seek($selector, &$ret) {
        list($tag, $key, $val, $exp, $no_key) = $selector;

        // xpath index
        if ($tag && $key && is_numeric($key)) {
            $count = 0;
            foreach ($this->children as $c) {
                if ($tag==='*' || $tag===$c->tag) {
                    if (++$count==$key) {
                        $ret[$c->_[HDOM_INFO_BEGIN]] = 1;
                        return;
                    }
                }
            } 
            return;
        }

        $end = (!empty($this->_[HDOM_INFO_END])) ? $this->_[HDOM_INFO_END] : 0;
        if ($end==0) {
            $parent = $this->parent;
            while (!isset($parent->_[HDOM_INFO_END]) && $parent!==null) {
                $end -= 1;
                $parent = $parent->parent;
            }
            $end += $parent->_[HDOM_INFO_END];
        }

        for($i=$this->_[HDOM_INFO_BEGIN]+1; $i<$end; ++$i) {
            $node = $this->dom->nodes[$i];
            $pass = true;

            if ($tag==='*' && !$key) {
                if (in_array($node, $this->children, true))
                    $ret[$i] = 1;
                continue;
            }

            // compare tag
            if ($tag && $tag!=$node->tag && $tag!=='*') {$pass=false;}
            // compare key
            if ($pass && $key) {
                if ($no_key) {
                    if (isset($node->attr[$key])) $pass=false;
                }
                else if (!isset($node->attr[$key])) $pass=false;
            }
            // compare value
            if ($pass && $key && $val  && $val!=='*') {
                $check = $this->match($exp, $val, $node->attr[$key]);
                // handle multiple class
                if (!$check && strcasecmp($key, 'class')===0) {
                    foreach(explode(' ',$node->attr[$key]) as $k) {
                        $check = $this->match($exp, $val, $k);
                        if ($check) break;
                    }
                }
                if (!$check) $pass = false;
            }
            if ($pass) $ret[$i] = 1;
            unset($node);
        }
    }

    protected function match($exp, $pattern, $value) {
        switch ($exp) {
            case '=':
                return ($value===$pattern);
            case '!=':
                return ($value!==$pattern);
            case '^=':
                return preg_match("/^".preg_quote($pattern,'/')."/", $value);
            case '$=':
                return preg_match("/".preg_quote($pattern,'/')."$/", $value);
            case '*=':
                if ($pattern[0]=='/')
                    return preg_match($pattern, $value);
                return preg_match("/".$pattern."/i", $value);
        }
        return false;
    }

    protected function parse_selector($selector_string) {
        // pattern of CSS selectors, modified from mootools
        $pattern = "/([\w-:\*]*)(?:\#([\w-]+)|\.([\w-]+))?(?:\[@?(!?[\w-]+)(?:([!*^$]?=)[\"']?(.*?)[\"']?)?\])?([\/, ]+)/is";
        preg_match_all($pattern, trim($selector_string).' ', $matches, PREG_SET_ORDER);
        $selectors = array();
        $result = array();
        //print_r($matches);

        foreach ($matches as $m) {
            $m[0] = trim($m[0]);
            if ($m[0]==='' || $m[0]==='/' || $m[0]==='//') continue;
            // for borwser grnreated xpath
            if ($m[1]==='tbody') continue;

            list($tag, $key, $val, $exp, $no_key) = array($m[1], null, null, '=', false);
            if(!empty($m[2])) {$key='id'; $val=$m[2];}
            if(!empty($m[3])) {$key='class'; $val=$m[3];}
            if(!empty($m[4])) {$key=$m[4];}
            if(!empty($m[5])) {$exp=$m[5];}
            if(!empty($m[6])) {$val=$m[6];}

            // convert to lowercase
            if ($this->dom->lowercase) {$tag=strtolower($tag); $key=strtolower($key);}
            //elements that do NOT have the specified attribute
            if (isset($key[0]) && $key[0]==='!') {$key=substr($key, 1); $no_key=true;}

            $result[] = array($tag, $key, $val, $exp, $no_key);
            if (trim($m[7])===',') {
                $selectors[] = $result;
                $result = array();
            }
        }
        if (count($result)>0)
            $selectors[] = $result;
        return $selectors;
    }

    function __get($name) {
        if (isset($this->attr[$name])) return $this->attr[$name];
        switch($name) {
            case 'outertext': return $this->outertext();
            case 'innertext': return $this->innertext();
            case 'plaintext': return $this->text();
            case 'xmltext': return $this->xmltext();
            default: return array_key_exists($name, $this->attr);
        }
    }

    function __set($name, $value) {
        switch($name) {
            case 'outertext': return $this->_[HDOM_INFO_OUTER] = $value;
            case 'innertext':
                if (isset($this->_[HDOM_INFO_TEXT])) return $this->_[HDOM_INFO_TEXT] = $value;
                return $this->_[HDOM_INFO_INNER] = $value;
        }
        if (!isset($this->attr[$name])) {
            $this->_[HDOM_INFO_SPACE][] = array(' ', '', ''); 
            $this->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_DOUBLE;
        }
        $this->attr[$name] = $value;
    }

    function __isset($name) {
        switch($name) {
            case 'outertext': return true;
            case 'innertext': return true;
            case 'plaintext': return true;
        }
        //no value attr: nowrap, checked selected...
        return (array_key_exists($name, $this->attr)) ? true : isset($this->attr[$name]);
    }

    function __unset($name) {
        if (isset($this->attr[$name]))
            unset($this->attr[$name]);
    }

    // camel naming conventions
    function getAllAttributes() {return $this->attr;}
    function getAttribute($name) {return $this->__get($name);}
    function setAttribute($name, $value) {$this->__set($name, $value);}
    function hasAttribute($name) {return $this->__isset($name);}
    function removeAttribute($name) {$this->__set($name, null);}
    function getElementById($id) {return $this->find("#$id", 0);}
    function getElementsById($id, $idx=null) {return $this->find("#$id", $idx);}
    function getElementByTagName($name) {return $this->find($name, 0);}
    function getElementsByTagName($name, $idx=null) {return $this->find($name, $idx);}
    function parentNode() {return $this->parent();}
    function childNodes($idx=-1) {return $this->children($idx);}
    function firstChild() {return $this->first_child();}
    function lastChild() {return $this->last_child();}
    function nextSibling() {return $this->next_sibling();}
    function previousSibling() {return $this->prev_sibling();}
}

// simple html dom parser
// -----------------------------------------------------------------------------
class simple_html_dom {
    public $root = null;
    public $nodes = array();
    public $callback = null;
    public $lowercase = false;
    protected $pos;
    protected $doc;
    protected $char;
    protected $size;
    protected $cursor;
    protected $parent;
    protected $noise = array();
    protected $token_blank = " \t\r\n";
    protected $token_equal = ' =/>';
    protected $token_slash = " />\r\n\t";
    protected $token_attr = ' >';
    // use isset instead of in_array, performance boost about 30%...
    protected $self_closing_tags = array('img'=>1, 'br'=>1, 'input'=>1, 'meta'=>1, 'link'=>1, 'hr'=>1, 'base'=>1, 'embed'=>1, 'spacer'=>1);
    protected $block_tags = array('root'=>1, 'body'=>1, 'form'=>1, 'div'=>1, 'span'=>1, 'table'=>1);
    protected $optional_closing_tags = array(
        'tr'=>array('tr'=>1, 'td'=>1, 'th'=>1),
        'th'=>array('th'=>1),
        'td'=>array('td'=>1),
        'li'=>array('li'=>1),
        'dt'=>array('dt'=>1, 'dd'=>1),
        'dd'=>array('dd'=>1, 'dt'=>1),
        'dl'=>array('dd'=>1, 'dt'=>1),
        'p'=>array('p'=>1),
        'nobr'=>array('nobr'=>1),
    );

    function __construct($str=null) {
        if ($str) {
            if (preg_match("/^http:\/\//i",$str) || is_file($str)) 
                $this->load_file($str); 
            else
                $this->load($str);
        }
    }

    function __destruct() {
        $this->clear();
    }

    // load html from string
    function load($str, $lowercase=true) {
        // prepare
        $this->prepare($str, $lowercase);
        // strip out comments
        $this->remove_noise("'<!--(.*?)-->'is");
        // strip out cdata
        $this->remove_noise("'<!\[CDATA\[(.*?)\]\]>'is", true);
        // strip out <style> tags
        $this->remove_noise("'<\s*style[^>]*[^/]>(.*?)<\s*/\s*style\s*>'is");
        $this->remove_noise("'<\s*style\s*>(.*?)<\s*/\s*style\s*>'is");
        // strip out <script> tags
        $this->remove_noise("'<\s*script[^>]*[^/]>(.*?)<\s*/\s*script\s*>'is");
        $this->remove_noise("'<\s*script\s*>(.*?)<\s*/\s*script\s*>'is");
        // strip out preformatted tags
        $this->remove_noise("'<\s*(?:code)[^>]*>(.*?)<\s*/\s*(?:code)\s*>'is");
        // strip out server side scripts
        $this->remove_noise("'(<\?)(.*?)(\?>)'s", true);
        // strip smarty scripts
        $this->remove_noise("'(\{\w)(.*?)(\})'s", true);

        // parsing
        while ($this->parse());
        // end
        $this->root->_[HDOM_INFO_END] = $this->cursor;
    }

    // load html from file
    function load_file() {
        $args = func_get_args();
        $this->load(call_user_func_array('file_get_contents', $args), true);
    }

    // set callback function
    function set_callback($function_name) {
        $this->callback = $function_name;
    }

    // remove callback function
    function remove_callback() {
        $this->callback = null;
    }

    // save dom as string
    function save($filepath='') {
        $ret = $this->root->innertext();
        if ($filepath!=='') file_put_contents($filepath, $ret);
        return $ret;
    }

    // find dom node by css selector
    function find($selector, $idx=null) {
        return $this->root->find($selector, $idx);
    }

    // clean up memory due to php5 circular references memory leak...
    function clear() {
        foreach($this->nodes as $n) {$n->clear(); $n = null;}
        if (isset($this->parent)) {$this->parent->clear(); unset($this->parent);}
        if (isset($this->root)) {$this->root->clear(); unset($this->root);}
        unset($this->doc);
        unset($this->noise);
    }
    
    function dump($show_attr=true) {
        $this->root->dump($show_attr);
    }

    // prepare HTML data and init everything
    protected function prepare($str, $lowercase=true) {
        $this->clear();
        $this->doc = $str;
        $this->pos = 0;
        $this->cursor = 1;
        $this->noise = array();
        $this->nodes = array();
        $this->lowercase = $lowercase;
        $this->root = new simple_html_dom_node($this);
        $this->root->tag = 'root';
        $this->root->_[HDOM_INFO_BEGIN] = -1;
        $this->root->nodetype = HDOM_TYPE_ROOT;
        $this->parent = $this->root;
        // set the length of content
        $this->size = strlen($str);
        if ($this->size>0) $this->char = $this->doc[0];
    }

    // parse html content
    protected function parse() {
        if (($s = $this->copy_until_char('<'))==='')
            return $this->read_tag();

        // text
        $node = new simple_html_dom_node($this);
        ++$this->cursor;
        $node->_[HDOM_INFO_TEXT] = $s;
        $this->link_nodes($node, false);
        return true;
    }

    // read tag info
    protected function read_tag() {
        if ($this->char!=='<') {
            $this->root->_[HDOM_INFO_END] = $this->cursor;
            return false;
        }
        $begin_tag_pos = $this->pos;
        $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next

        // end tag
        if ($this->char==='/') {
            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            $this->skip($this->token_blank_t);
            $tag = $this->copy_until_char('>');

            // skip attributes in end tag
            if (($pos = strpos($tag, ' '))!==false)
                $tag = substr($tag, 0, $pos);

            $parent_lower = strtolower($this->parent->tag);
            $tag_lower = strtolower($tag);

            if ($parent_lower!==$tag_lower) {
                if (isset($this->optional_closing_tags[$parent_lower]) && isset($this->block_tags[$tag_lower])) {
                    $this->parent->_[HDOM_INFO_END] = 0;
                    $org_parent = $this->parent;

                    while (($this->parent->parent) && strtolower($this->parent->tag)!==$tag_lower)
                        $this->parent = $this->parent->parent;

                    if (strtolower($this->parent->tag)!==$tag_lower) {
                        $this->parent = $org_parent; // restore origonal parent
                        if ($this->parent->parent) $this->parent = $this->parent->parent;
                        $this->parent->_[HDOM_INFO_END] = $this->cursor;
                        return $this->as_text_node($tag);
                    }
                }
                else if (($this->parent->parent) && isset($this->block_tags[$tag_lower])) {
                    $this->parent->_[HDOM_INFO_END] = 0;
                    $org_parent = $this->parent;

                    while (($this->parent->parent) && strtolower($this->parent->tag)!==$tag_lower)
                        $this->parent = $this->parent->parent;

                    if (strtolower($this->parent->tag)!==$tag_lower) {
                        $this->parent = $org_parent; // restore origonal parent
                        $this->parent->_[HDOM_INFO_END] = $this->cursor;
                        return $this->as_text_node($tag);
                    }
                }
                else if (($this->parent->parent) && strtolower($this->parent->parent->tag)===$tag_lower) {
                    $this->parent->_[HDOM_INFO_END] = 0;
                    $this->parent = $this->parent->parent;
                }
                else
                    return $this->as_text_node($tag);
            }

            $this->parent->_[HDOM_INFO_END] = $this->cursor;
            if ($this->parent->parent) $this->parent = $this->parent->parent;

            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        $node = new simple_html_dom_node($this);
        $node->_[HDOM_INFO_BEGIN] = $this->cursor;
        ++$this->cursor;
        $tag = $this->copy_until($this->token_slash);

        // doctype, cdata & comments...
        if (isset($tag[0]) && $tag[0]==='!') {
            $node->_[HDOM_INFO_TEXT] = '<' . $tag . $this->copy_until_char('>');

            if (isset($tag[2]) && $tag[1]==='-' && $tag[2]==='-') {
                $node->nodetype = HDOM_TYPE_COMMENT;
                $node->tag = 'comment';
            } else {
                $node->nodetype = HDOM_TYPE_UNKNOWN;
                $node->tag = 'unknown';
            }

            if ($this->char==='>') $node->_[HDOM_INFO_TEXT].='>';
            $this->link_nodes($node, true);
            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        // text
        if ($pos=strpos($tag, '<')!==false) {
            $tag = '<' . substr($tag, 0, -1);
            $node->_[HDOM_INFO_TEXT] = $tag;
            $this->link_nodes($node, false);
            $this->char = $this->doc[--$this->pos]; // prev
            return true;
        }

        if (!preg_match("/^[\w-:]+$/", $tag)) {
            $node->_[HDOM_INFO_TEXT] = '<' . $tag . $this->copy_until('<>');
            if ($this->char==='<') {
                $this->link_nodes($node, false);
                return true;
            }

            if ($this->char==='>') $node->_[HDOM_INFO_TEXT].='>';
            $this->link_nodes($node, false);
            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        // begin tag
        $node->nodetype = HDOM_TYPE_ELEMENT;
        $tag_lower = strtolower($tag);
        $node->tag = ($this->lowercase) ? $tag_lower : $tag;

        // handle optional closing tags
        if (isset($this->optional_closing_tags[$tag_lower]) ) {
            while (isset($this->optional_closing_tags[$tag_lower][strtolower($this->parent->tag)])) {
                $this->parent->_[HDOM_INFO_END] = 0;
                $this->parent = $this->parent->parent;
            }
            $node->parent = $this->parent;
        }

        $guard = 0; // prevent infinity loop
        $space = array($this->copy_skip($this->token_blank), '', '');

        // attributes
        do {
            if ($this->char!==null && $space[0]==='') break;
            $name = $this->copy_until($this->token_equal);
            if($guard===$this->pos) {
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                continue;
            }
            $guard = $this->pos;

            // handle endless '<'
            if($this->pos>=$this->size-1 && $this->char!=='>') {
                $node->nodetype = HDOM_TYPE_TEXT;
                $node->_[HDOM_INFO_END] = 0;
                $node->_[HDOM_INFO_TEXT] = '<'.$tag . $space[0] . $name;
                $node->tag = 'text';
                $this->link_nodes($node, false);
                return true;
            }

            // handle mismatch '<'
            if($this->doc[$this->pos-1]=='<') {
                $node->nodetype = HDOM_TYPE_TEXT;
                $node->tag = 'text';
                $node->attr = array();
                $node->_[HDOM_INFO_END] = 0;
                $node->_[HDOM_INFO_TEXT] = substr($this->doc, $begin_tag_pos, $this->pos-$begin_tag_pos-1);
                $this->pos -= 2;
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                $this->link_nodes($node, false);
                return true;
            }

            if ($name!=='/' && $name!=='') {
                $space[1] = $this->copy_skip($this->token_blank);
                $name = $this->restore_noise($name);
                if ($this->lowercase) $name = strtolower($name);
                if ($this->char==='=') {
                    $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                    $this->parse_attr($node, $name, $space);
                }
                else {
                    //no value attr: nowrap, checked selected...
                    $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_NO;
                    $node->attr[$name] = true;
                    if ($this->char!='>') $this->char = $this->doc[--$this->pos]; // prev
                }
                $node->_[HDOM_INFO_SPACE][] = $space;
                $space = array($this->copy_skip($this->token_blank), '', '');
            }
            else
                break;
        } while($this->char!=='>' && $this->char!=='/');

        $this->link_nodes($node, true);
        $node->_[HDOM_INFO_ENDSPACE] = $space[0];

        // check self closing
        if ($this->copy_until_char_escape('>')==='/') {
            $node->_[HDOM_INFO_ENDSPACE] .= '/';
            $node->_[HDOM_INFO_END] = 0;
        }
        else {
            // reset parent
            if (!isset($this->self_closing_tags[strtolower($node->tag)])) $this->parent = $node;
        }
        $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        return true;
    }

    // parse attributes
    protected function parse_attr($node, $name, &$space) {
        $space[2] = $this->copy_skip($this->token_blank);
        switch($this->char) {
            case '"':
                $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_DOUBLE;
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                $node->attr[$name] = $this->restore_noise($this->copy_until_char_escape('"'));
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                break;
            case '\'':
                $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_SINGLE;
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                $node->attr[$name] = $this->restore_noise($this->copy_until_char_escape('\''));
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                break;
            default:
                $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_NO;
                $node->attr[$name] = $this->restore_noise($this->copy_until($this->token_attr));
        }
    }

    // link node's parent
    protected function link_nodes(&$node, $is_child) {
        $node->parent = $this->parent;
        $this->parent->nodes[] = $node;
        if ($is_child)
            $this->parent->children[] = $node;
    }

    // as a text node
    protected function as_text_node($tag) {
        $node = new simple_html_dom_node($this);
        ++$this->cursor;
        $node->_[HDOM_INFO_TEXT] = '</' . $tag . '>';
        $this->link_nodes($node, false);
        $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        return true;
    }

    protected function skip($chars) {
        $this->pos += strspn($this->doc, $chars, $this->pos);
        $this->char = ($this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
    }

    protected function copy_skip($chars) {
        $pos = $this->pos;
        $len = strspn($this->doc, $chars, $pos);
        $this->pos += $len;
        $this->char = ($this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        if ($len===0) return '';
        return substr($this->doc, $pos, $len);
    }

    protected function copy_until($chars) {
        $pos = $this->pos;
        $len = strcspn($this->doc, $chars, $pos);
        $this->pos += $len;
        $this->char = ($this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        return substr($this->doc, $pos, $len);
    }

    protected function copy_until_char($char) {
        if ($this->char===null) return '';

        if (($pos = strpos($this->doc, $char, $this->pos))===false) {
            $ret = substr($this->doc, $this->pos, $this->size-$this->pos);
            $this->char = null;
            $this->pos = $this->size;
            return $ret;
        }

        if ($pos===$this->pos) return '';
        $pos_old = $this->pos;
        $this->char = $this->doc[$pos];
        $this->pos = $pos;
        return substr($this->doc, $pos_old, $pos-$pos_old);
    }

    protected function copy_until_char_escape($char) {
        if ($this->char===null) return '';

        $start = $this->pos;
        while(1) {
            if (($pos = strpos($this->doc, $char, $start))===false) {
                $ret = substr($this->doc, $this->pos, $this->size-$this->pos);
                $this->char = null;
                $this->pos = $this->size;
                return $ret;
            }

            if ($pos===$this->pos) return '';

            if ($this->doc[$pos-1]==='\\') {
                $start = $pos+1;
                continue;
            }

            $pos_old = $this->pos;
            $this->char = $this->doc[$pos];
            $this->pos = $pos;
            return substr($this->doc, $pos_old, $pos-$pos_old);
        }
    }

    // remove noise from html content
    protected function remove_noise($pattern, $remove_tag=false) {
        $count = preg_match_all($pattern, $this->doc, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);

        for ($i=$count-1; $i>-1; --$i) {
            $key = '___noise___'.sprintf('% 3d', count($this->noise)+100);
            $idx = ($remove_tag) ? 0 : 1;
            $this->noise[$key] = $matches[$i][$idx][0];
            $this->doc = substr_replace($this->doc, $key, $matches[$i][$idx][1], strlen($matches[$i][$idx][0]));
        }

        // reset the length of content
        $this->size = strlen($this->doc);
        if ($this->size>0) $this->char = $this->doc[0];
    }

    // restore noise to html content
    function restore_noise($text) {
        while(($pos=strpos($text, '___noise___'))!==false) {
            $key = '___noise___'.$text[$pos+11].$text[$pos+12].$text[$pos+13];
            if (isset($this->noise[$key]))
                $text = substr($text, 0, $pos).$this->noise[$key].substr($text, $pos+14);
        }
        return $text;
    }

    function __toString() {
        return $this->root->innertext();
    }

    function __get($name) {
        switch($name) {
            case 'outertext': return $this->root->innertext();
            case 'innertext': return $this->root->innertext();
            case 'plaintext': return $this->root->text();
        }
    }

    // camel naming conventions
    function childNodes($idx=-1) {return $this->root->childNodes($idx);}
    function firstChild() {return $this->root->first_child();}
    function lastChild() {return $this->root->last_child();}
    function getElementById($id) {return $this->find("#$id", 0);}
    function getElementsById($id, $idx=null) {return $this->find("#$id", $idx);}
    function getElementByTagName($name) {return $this->find($name, 0);}
    function getElementsByTagName($name, $idx=-1) {return $this->find($name, $idx);}
    function loadFile() {$args = func_get_args();$this->load(call_user_func_array('file_get_contents', $args), true);}
}



?>