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
	 * @var int Maximum number of requests that will run concurrently.
	 */
	public $maxReqs = 25;
	
	/**
	 * @var int Timeout for the curl_multi_select calls
	 */
	public $selectTimeout = 1;
	
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
	 * An associative array of requests to be made active.
	 *
	 * @var array Request ID => cURL handle
	 */
	protected $running = array();
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
		$this->queue($id);
		$this->rCount++;
		return $id;
	}

	// add array of CurlRequests
	public function addArray(&$requests) {
		foreach($requests as $id => &$request) {
			if(is_int($id)) { $id = $this->rCount; }
			$ids[]=$id;
			$this->add($request, $id);
		}
		return $ids;
	}

	protected function queue($id, $add = true) {
		if($add) {
			$this->queue[] = $id;
		} else {
			$k = array_search($id, $this->queue);
			unset($this->queue[$k]);
	}
	
	// execute added CurlRequests
	public function perform() {
		if(!($this->mh = curl_multi_init())) {
			$this->multiErrors[] = "Failed to initialize multi handle";
			return;
		}
		
		$this->performRequests();

		curl_multi_close($this->mh);
	}

/******
** Internal methods
******/

	protected function addHandle($id) {
		if(($n = curl_multi_add_handle($this->mh,$this->running[$id])) > 0) {
			if(is_resource($this->running[$id])) {
				curl_close($this->running[$id]);
			}
			unset($this->running[$id]);
			$this->requests[$id]->event('curlerror', array(999, 'Failed to add to multihandle'));
			return false;
		} else {
			return true;
		}
	}

	protected function removeHandle($id) {
		if(($n = curl_multi_remove_handle($this->mh,$this->running[$id])) === false) {
			if(is_resource($this->running[$id])) {
				curl_close($this->running[$id]);
			}
			unset($this->running[$id]);
			$this->requests[$id]->event('curlerror', array(999, 'Failed to remove from multihandle'));
			return false;
		} else {
			return true;
		}
	}

	protected function setOptions($id) {
		$req = $this->requests[$id];
		
		if(curl_setopt_array($this->running[$id], $req->setOptions($this->defaultOptions)) === false) {
			unset($this->running[$id]);
			$req->event('curlerror', array(999, 'Failed to set options'));
			return false;
		} else {
			return true;
		}
	}

	protected function prepareRequest($id) {
		$req = $this->requests[$id];
		
		if(!array_key_exists($id, $this->running)) {
			$this->running[$id] = '';
		}
		// Trigger before event
		$req->event('before');
		
		if(!is_resource($this->running[$id])) {
			// If it's the first run, initialize the handle
			if(($handle = curl_init()) !== false) {
				$this->running[$id] = $handle;
				
				if($this->setOptions($id)) { 
					$this->addHandle($id); 
				}
			} else {
				unset($this->running[$id]);
				$req->event('curlerror', array(999, 'Failed to init handle'));
			}	
		} else {
			// otherwise just set the options again and re-add the handle
			if($this->setOptions($id)) {
				$this->addHandle($id);
			}
		}
	}

	protected function performRequests() {
		$qCount = count($this->queue);
		$maxStart = ($qCount < $this->maxReqs) ? $qCount : $this->maxReqs;
		for($i=0; $i < $maxStart; $i++) {
			$idStart = array_shift($this->queue);
			$this->prepareRequest($idStart);
		}
	
		do {
			while(($execResult = curl_multi_exec($this->mh, $stillRunning)) == CURLM_CALL_MULTI_PERFORM);
			if($execResult != CURLM_OK) {
				break;
			}
			// a request was just completed -- find out which one
			while($done = curl_multi_info_read($this->mh)) {
				$id = array_search($done['handle'], $this->running);
				$req = $this->requests[$id];
				
				// get the info and content returned on the request
				if (($ern = curl_errno($this->running[$id])) === 0 ) {
					$response = curl_multi_getcontent($this->running[$id]);
					$info = curl_getinfo($this->running[$id]);
					$req->execCount++;
					$req->event('parse', array($response,$info));
					$req->event('decide');
				} else {
					$msg = curl_error($this->running[$id]);
					$req->event('curlerror', array($ern, $msg));
				}

				// remove the curl handle that just completed from the multi handle
				$this->removeHandle($id);
				
				$req->event('after');
				if($req->execCount > $req->maxExecCount) {
					$req->event('maxexec', array($req->execCount));
				}
				
				if(!$req->keep) {
					if(is_resource($this->running[$id])) { 
						curl_close($this->running[$id]);
					}
					unset($this->running[$id]);
					
					// Add a new request
					$idNext = array_shift($this->queue);
					$this>prepareRequest($idNext);
				} else {
					// Re-add current request
					$this>prepareRequest($id);
				}
				
			}
			if ($stillRunning) {
				$selectResult = curl_multi_select($master, $this->selectTimeout);
			}
		} while ($stillRunning);
	}
}
?>