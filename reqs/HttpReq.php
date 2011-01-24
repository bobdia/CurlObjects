<?php


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
	// assoc. array of HTTP parameters, used for both GET and POST
	public $args; 
	// Passing an array to CURLOPT_POSTFIELDS will send the data as multipart/form-data, 
	// while passing a URL-encoded string will send the data
	// as application/x-www-form-urlencoded. 
	public $stringPost = true;
	public $argsSeparator = '&'; // default is '&'
	public $argsArray = '[]'; // default is '[]'
	public $argsType = PHP_QUERY_RFC1738; // PHP_QUERY_RFC1738 or PHP_QUERY_RFC3986
	
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
					$this->options[CURLOPT_POSTFIELDS] = HttpReq::encodeArgs($this->args, $this->argsSeparator, $this->argsArray);
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
	
	static public function parseHeaders($str) {
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
?>