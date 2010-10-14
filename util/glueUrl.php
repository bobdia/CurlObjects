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