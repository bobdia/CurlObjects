<?php
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
?>