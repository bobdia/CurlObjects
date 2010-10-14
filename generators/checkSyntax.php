<?php

set_time_limit(0);
$dirs = new RecursiveDirectoryIterator('../', 0);
$files = new RecursiveIteratorIterator($dirs);
$s= '';
foreach ($files as $path => $info) {
	if((strpos($path, '.svn') === false) && (strpos($path, '.php') !== false)) {
		//if( runkit_lint_file($path) === false )
			$s .= 'Syntax error in: '.$path."\n";
	}
}
file_put_contents('./log.txt',$s);
?>