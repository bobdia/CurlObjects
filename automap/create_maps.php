<?php
/**************************
** COPYRIGHT 2006-2009 curlobjects.com
** This work is licensed under a Creative Commons Attribution-Share Alike 3.0 License
** http://creativecommons.org/licenses/by-sa/3.0/
**************************/

$phpCliPath = 'php'; // windows: 'w:\usr\local\php\php.exe';
set_time_limit(0);
$synLog = '';
$mapLog = '';
$logFile = 'create_maps.log';

$baseFiles = new DirectoryIterator('../');
//$baseFiles = new RecursiveIteratorIterator($bdirs);


foreach ($baseFiles as $path => $info) {
	if((strpos($path, '.svn') === false) && (strpos($path, '.php') !== false)) {
		$syn = shell_exec($phpCliPath.' -l '.$path);
		if(strpos($syn,'No syntax errors') !== false) {
			$filename = substr($path,7);
			$cm = $phpCliPath.' Automap.phk register_scripts base.map ./base '.$filename;
			$emp = shell_exec($cm);
			if(!empty($emp)) {
				$mapLog .= 'Automap error for command: '.$cm."\r\n";
				$mapLog .= '-- path: '.$path."\r\n";
				$mapLog .= '-- output: '."\r\n".$emp."\r\n\r\n";
			}
		} else {
			$synLog .= 'Syntax error in: '.$path."\r\n";
		}
	}
}

$dirs = new RecursiveDirectoryIterator('../../lib', 0);
$libFiles = new RecursiveIteratorIterator($dirs);

foreach ($libFiles as $path => $info) {
	if((strpos($path, '.svn') === false) && (strpos($path, '.php') !== false)) {
		$syn = shell_exec($phpCliPath.' -l '.$path);
		if(strpos($syn,'No syntax errors') !== false) {
			$filename = substr($path,6);
			$cm = $phpCliPath.' Automap.phk register_scripts lib.map ./lib '.$filename;
			$emp = shell_exec($cm);
			if(!empty($emp)) {
				$mapLog .= 'Automap error for command: '.$cm."\r\n";
				$mapLog .= '-- path: '.$path."\r\n";
				$mapLog .= '-- output: '."\r\n".$emp."\r\n\r\n";
			}
		} else {
			$synLog .= 'Syntax error in: '.$path."\r\n";
		}
	}
}

file_put_contents($logFile, $synLog."\r\n\r\n".$mapLog);
?>