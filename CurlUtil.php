<?php

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