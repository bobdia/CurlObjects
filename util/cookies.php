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