<?php


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
?>