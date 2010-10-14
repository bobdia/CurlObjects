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