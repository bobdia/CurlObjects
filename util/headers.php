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