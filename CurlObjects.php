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

/**
 * CurlBase
 *
 * This class handles groups of CurlRequest objects. You can execute them concurrently
 * or not, add default cURL options, attach default event callbacks and more.
 *
 * @package	CurlObjects
 * @author	Robert Diaes
 */
class CurlBase {
	/**
	 * If you are having problems with curl_multi or you just don't need concurrent requests,
	 * change to false and it will work with non-multi curl. You will still be able to execute
	 * multiple requests (but they won't be executed concurrently).
	 *
	 * @var bool Toggles use of the curl_multi interface.
	 */
	public $multi = true;
	/**
	 * @var int Maximum number of requests that will run concurrently.
	 */
	public $maxChunk = 25;
	/**
	 * @var int Delay between requests in seconds, only works with $multi set to false.
	 */
	public $delaySingle = 0;
	/**
	 * @var int Delay between chunks in seconds, only works with $multi set to true.
	 */
	public $delayChunks = 0;
	/**
	 * @var int Function to be run between chunks, called with $this (CurlBase) as it's only argument.
	 */
	public $chunkCallback = null;
	/**
	 * @var array The pool of CurlRequests.
	 */
	public $requests = array();
	/**
	 * An associative array of currently active requests.
	 *
	 * @var array Request ID => cURL handle
	 */
	protected $active = array();
	/**
	 * Default cURL Options
	 * 
	 * These options will be added to each request object.
	 * Duplicate options will be overriden by values in CurlRequest::$options.
	 *
	 * @var array Format is CURL_CONSTANT => value
	 */
	public $defaultOptions = array(
				//CURLOPT_RETURNTRANSFER => true
	);
	/**
	 * Default callbacks
	 * 
	 * These callbacks will be attached to each request's corresponding event.
	 * Each sub-array will be given as arguments to the request's attach() method.
	 *
	 * @var array Format is (event_name, callable, position)
	 */
	public $defaultAttach = array(
		//array($event, $callback, $position),
		//array($event, $callback, $position)
	);
	/**
	 * @var array Stores errors regarding the cURL multi-handle.
	 */
	public $multiErrors = array();
	/**
	 * @var resource The cURL multi-handle.
	 */
	protected $mh;
	/**
	 * This is used for auto-generating request IDs. Will not re-use IDs if you remove requests.
	 *
	 * @var int Total number of requests that have been added to the pool.
	 */
	protected $rCount = 0;


/*
** Constructor
*/
	public function __construct($requests=null,$options=null) {

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
		// Attach default callbacks
		foreach($this->defaultAttach as $at) {
			$request->attach($at[0], $at[1], $at[2]);
		}
		$this->requests[$id] = $request;
		$this->requests[$id]->_id = $id;
		$this->active($id, TRUE);
		$this->rCount++;
		return $id;
	}

	// add array of CurlRequests
	public function addArray(&$requests) {
		foreach($requests as $i => &$request) {
			if(is_int($i)) { $i = $this->rCount; }
			$ids[]=$i;
			$this->add($request, $i);
		}
		return $ids;
	}

	protected function active($k, $active=null) {
		if(is_null($active)) {
			return array_key_exists($k, $this->active);
		} else if($active === true) {
			$this->active[$k] = $this->requests[$k]->_handle;
		} else if($active === false) {
			unset($this->active[$k]);
		}
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
						$this->performRequests();
					} while(count($this->active)>0);

					// Callback for cleanup between chunks
					if($this->chunkCallback) {
						// pass a reference to $this CurlBase as the only argument
						call_user_func_array($this->chunkCallback, array($this));
					}

					// delay if needed
					if($this->delayChunks > 0) {
						sleep($this->delayChunks);
					}
				}
			}
			// If we only have one chunk
			else {
				// this will only loop when requests use $keep=true
				do {

					// perform requests in the active pool
					$this->performRequests();

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
			
			$this->active($k, FALSE);
			$this->requests[$k]->event('curlerror', array(999, 'Failed to add to multihandle'));
		}
	}

	protected function removeHandle($k) {
		if(($n = curl_multi_remove_handle($this->mh,$this->active[$k])) === false) {
			$this->active($k, FALSE);
			$this->requests[$k]->event('curlerror', array(999, 'Failed to remove from multihandle'));
		}
	}

	protected function setOptions($k) {
		$req = $this->requests[$k];
		
		if(curl_setopt_array($this->active[$k], $req->setOptions($this->defaultOptions)) === false) {
			$req->event('curlerror', array(999, 'Failed to set options'));
			$this->active($k, FALSE);
			return false;
		} else {
			return true;
		}
	}

	protected function prepareRequests() {
		foreach(array_keys($this->active) as $k) {
			$req = $this->requests[$k];
			
			// Trigger before event
			$req->event('before');
			
			// If it's the first run, initialize the handle
			if(!is_resource($req->_handle)) {
				if(($handle = curl_init()) !== false) {
					$req->_handle = $handle;
					
					$this->active($k, TRUE);
					
					if($this->setOptions($k)) { 
						$this->addHandle($k); 
					}
				} else {
					$this->active($k, FALSE);
				}	
			} else {
				if($this->setOptions($k)) {
					$this->addHandle($k);
				}
			}
		}
	
	}

	protected function performRequests() {
		if($this->multi) {
			
			$this->prepareRequests();
	
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
				if($this->delaySingle > 0) {
					sleep($this->delaySingle);
				}
				// Execute the request
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
				$this->active($k, FALSE);
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





class HttpReq extends CurlReq {
	/* Options */
	public $method = 'get'; // HTTP method to use
	
	public $options = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_HEADER => 1,
			CURLOPT_AUTOREFERER => 1,
			CURLOPT_FOLLOWLOCATION => 1,
			CURLOPT_MAXREDIRS => 30,
			CURLOPT_ENCODING => '',
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0
			/*	CURLOPT_HTTP_VERSION
					CURL_HTTP_VERSION_NONE  (default, lets CURL decide which version to use),
					CURL_HTTP_VERSION_1_0  (forces HTTP/1.0)
					CURL_HTTP_VERSION_1_1  (forces HTTP/1.1).
			*/
			);
	
	public $args; // assoc. array of HTTP parameters, used for both GET and POST
	// Passing an array to CURLOPT_POSTFIELDS will encode the data as multipart/form-data, 
	// while passing a URL-encoded string will encode the data as application/x-www-form-urlencoded. 
	public $stringPost = true;
	public $argsSeparator = '&'; // default is '&'
	public $argsArray = '[]'; // default is '[]'
	
	public $cookieFile; // full path to cookie file
	
	public $parseHeaders = true; // set to true to parse headers
	public $parseCookies = true; // set to true to parse cookies, needs an assoc array in $headers
	public $parseDOM = false; // 'php' = DOMDocument, 'xml' = SimpleXML, 'simple' = simplehtmldom
	
	/* Data */
	public $cookies; // cookies assoc arrays
	public $headers; // headers assoc arrays
	public $dom; // DOM object
	
	public $body; // body string
	public $head; // headers string
	
	public $status; // HTTP status code
	public $info; // cURL handle info array
	
	public $events = array(
			'before' => array(0),
			'curlerror' => array(0),
			'parse' => array(0),
			'decide' => array(0),
			'success' => array(0),
			'emptybody' => array(0),
			'httperror' => array(0),
			'after' => array(0),
			'fail' => array(0)
			);
	
	public function __construct($url, $options=null) {
		parent::__construct($url,$options);
	}
	
	/* Helpers */
	
	// create a cookie file
	// warning: you have to clean up temp files yourself
	public function cookieFile($dir=null,$prefix=null) {
		if(!$dir) { $dir = getcwd(); }
		if(!$prefix) { $prefix = get_class(); }
		$this->cookieFile = tempnam($dir,$prefix);
		return $this->cookieFile;
	}
	
	// sets a cookie from a string or $this->cookies array
	public function cookie($cookieArray,$deep=false) {
		if(is_array($cookieArray)) {
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
		} else {
			$merged = (string) $cookieArray;
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
	
	/* Callbacks */
	
	// If no curl error and http status code >= 400
	protected function httperror($code) {
		$this->logError(1001, "HTTP error: $code");
		$this->keep = false;
		if($this->singleFail) {
			$this->event('fail', array('httperror'));
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
			$this->headers = HttpReq::parseHeaders($this->head);
		}
		if($this->headers && $this->parseCookies) {
			$this->cookies = HttpReq::parseCookies($this->headers);
		}
		if($this->body && $this->parseDOM) {
			$this->dom = HttpReq::parseDOM($this->body, $this->parseDOM);
		}
	}
	
	protected function decide() {
		if($this->status >= 400) {
			$this->event('httperror', array($this->status));
		} elseif($this->isReturnTransfer() && empty($this->body)) {
			$this->event('emptybody');
		} else {
			$this->event('success');
		}	
	}
	
	// set curl options and do magic
	public function setOptions($defaults=null) {
		if(is_array($defaults)) {
			$this->options = $defaults + $this->options;
		}
		switch(strtolower($this->method)) {
		case 'get':
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
				$this->url = HttpReq::cleanGetArgs($this->url);
				$this->url .= '?' . HttpReq::encodeArgs($this->args, $this->argsSeparator, $this->argsArray);
			}
			break;
		case 'post':
			$this->options[CURLOPT_POST] = 1;
			if(isset($this->options[CURLOPT_HTTPGET])) {
				unset($this->options[CURLOPT_HTTPGET]);
			}
			if($this->args) {
				if($this->stringPost) {
					$this->options[CURLOPT_POSTFIELDS] = HttpReq::vars($this->args, $this->argsSeparator, $this->argsArray);
				} else {
					$this->options[CURLOPT_POSTFIELDS] = $this->args;
				}
			}
			break;
		}
		$this->options[CURLOPT_URL] = $this->url;
		if($this->cookieFile) {
			// possibly dangerous if cookiejar is used by many reqs, might overwrite newer data
			$this->options[CURLOPT_COOKIEFILE] = $this->cookieFile;
			$this->options[CURLOPT_COOKIEJAR] = $this->cookieFile;
		}
		return $this->options;
	}
	
	/* Utility methods */
	
	static public function cleanGetArgs($url) {
		if(($p = strpos($url, '?')) !== false) {
			$url = substr($url, 0, $p);
		}
		return $url;
	}
	
	static public function encodeArgs($args, $sep='&',$suffix='[]') {
		$str = '';
		foreach( $args as $k => $v) {
			if(is_array($v)) {
				foreach($v as $ki => $vi) {
					if(!is_int($ki)) {
						$arraySuffix = $suffix[0] . $ki . $suffix[1];
					} else {
						$arraySuffix = $suffix;
					}
					$str .= urlencode($k) . $arraySuffix.'='. urlencode($vi) . $sep;
				}
			} else {
				$str .= urlencode($k) . '=' . urlencode($v) . $sep;
			}
		}
		return substr($str, 0, -1);
	}
	
	static public function parseHeaders($head) {
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
	
	static public function parseCookies($headers, $other=false) {
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
						if($other && in_array( $c[0], array('domain', 'expires', 'path', 'secure', 'comment'))) {
							switch($c[0]) {
							case 'expires':
								$c[1] = strtotime($c[1]);
								break;
							case 'secure':
								$c[1] = true;
								break;
							}
							$cookies[$i][$c[0]] = $c[1];
						} else {
							$cookies[$i][$c[0]] = $c[1];
						}
					}
				}
			}
		}
		return $cookies;
	}
	
	static public function parseDOM($html, $parser){
		if($parser == 'php') {
			libxml_use_internal_errors(true);
			$dom = new DOMDocument;
			if( $dom->loadHTML($html) !== false) {
				return $dom;
			}
		} elseif($parser == 'simple') {
			// http://simplehtmldom.sourceforge.net/manual.htm
			if($dom = str_get_html($html)) {
				return $dom;
			}
		} elseif($parser == 'xml') {
			libxml_use_internal_errors(true);
			if(($dom = simplexml_load_string($html)) !== false) {
				return $dom;
			}
		}
		$this->logError(1008,'Failed to parse DOM. Parser:' . $parser);
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

		$this->states[$state]['options'][$opt] = $val;

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
		if(!isset($this->events[$event])) {
			$this->events[$event] = array(0);
		}
		
		if($append === 1) {
			// default, append to the end of the array
			$this->events[$event][] = $callback;
		} elseif($append === 0) {
			// replace existing callback array
			$this->events[$event] = array($callback);
		} elseif($append === -1) {
			// in the beginning of the array
			$this->events[$event] = array_unshift($this->events[$event], $callback);
		}
	}
	
	public function event($type, $args = null){
		parent::event($type, $args);

		$stateEvent = $this->state . ucfirst($type);
		if(method_exists($this,$stateEvent)) {
			if(!isset($this->events[$stateEvent])) {
				$this->events[$stateEvent] = array(0);
			}

		}

		if(isset($this->events[$stateEvent]) && is_array($this->events[$stateEvent])) {
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


public static function varsToArray($str){
	$arr = array();
	parse_str($str, $arr);
	return $arr;
}

public static $ifconfigRegex = '@addr:(?=([0-9.]+))(?!(?:10\.|127\.|172\.[1-3][6-9_0]\.|192\.|169\.254\.[0-9.]+))@';

public static function discoverInterfaces() {
	$a = shell_exec( 'ifconfig -a' );
	preg_match_all( self::$ifconfigRegex, $a, $m);
	return $m[1];
}

public static $linkRegex = '@<\s*a\s*[^>]*?href\s*=[\"\'\s]*(.*?)[\"\'\s]*[^>]*?>(.*?)<\s*/\s*a\s*>@is';
public static $urlRegex = '@(https?://[a-zA-z0-9-_]+)@i';
public static $baseRegex = '@<\s*base\s*href\s*=[\"\'\s]*(.+?)[\"\'\s]*[^>]*/?>@i';

public static function links($str,$mode='html',$base=null) {
	$links = array();
	switch($html) {

	case 'html':
		preg_match_all(self::$linkRegex,$str,$m);
		if(($p = stripos($str,'<base')) !== false) {
			preg_match(self::$baseRegex,$str,$b);
			$base = $b[1];
			//expand links
		}
		if($base)
			array_walk($m[1], array(self, 'resolveUrl'),$base);
		$links = array($m[1],$m[2]);
		break;
	case 'txt':
		preg_match_all(self::$urlRegex,$str,$m);
		break;
	}
	return $links;		
}

public static function resolveUrl($url,$i,$base) {
	if (!strlen($base)) return $url;
	// Step 2
	if (!strlen($url)) return $base;
	// Step 3
	if (preg_match('!^[a-z]+:!i', $url)) return $url;
	$base = parse_url($base);
	if ($url{0} == "#") {
		// Step 2 (fragment)
		$base['fragment'] = substr($url, 1);
		return self::glueUrl($base);
	}
	unset($base['fragment']);
	unset($base['query']);
	if (substr($url, 0, 2) == "//") {
		// Step 4
		return self::glueUrl(array(
			'scheme'=>$base['scheme'],
			'path'=>substr($url,2),
		));
	} else if ($url{0} == "/") {
		// Step 5
		$base['path'] = $url;
	} else {
		// Step 6
		$path = explode('/', $base['path']);
		$url_path = explode('/', $url);
		// Step 6a: drop file from base
		array_pop($path);
		// Step 6b, 6c, 6e: append url while removing "." and ".." from
		// the directory portion
		$end = array_pop($url_path);
		foreach ($url_path as $segment) {
			if ($segment == '.') {
				// skip
			} else if ($segment == '..' && $path && $path[sizeof($path)-1] != '..') {
				array_pop($path);
			} else {
				$path[] = $segment;
			}
		}
		// Step 6d, 6f: remove "." and ".." from file portion
		if ($end == '.') {
			$path[] = '';
		} else if ($end == '..' && $path && $path[sizeof($path)-1] != '..') {
			$path[sizeof($path)-1] = '';
		} else {
			$path[] = $end;
		}
		// Step 6h
		$base['path'] = join('/', $path);

	}
	// Step 7
	return self::glueUrl($base);
}

public static function glueUrl($parsed) {
	$uri = isset($parsed['scheme']) ? $parsed['scheme'].':'.((strtolower($parsed['scheme']) == 'mailto') ? '' : '//') : '';
	$uri .= isset($parsed['user']) ? $parsed['user'].(isset($parsed['pass']) ? ':'.$parsed['pass'] : '').'@' : '';
	$uri .= isset($parsed['host']) ? $parsed['host'] : '';
	$uri .= isset($parsed['port']) ? ':'.$parsed['port'] : '';
	if(isset($parsed['path']))
		$uri .= (substr($parsed['path'], 0, 1) == '/') ? $parsed['path'] : ('/'.$parsed['path']);
	$uri .= isset($parsed['query']) ? '?'.$parsed['query'] : '';
	$uri .= isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';
	return $uri;
}
}


?>