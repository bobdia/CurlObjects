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
