public static $ifconfigRegex = '@addr:(?=([0-9.]+))(?!(?:10\.|127\.|172\.[1-3][6-9_0]\.|192\.|169\.254\.[0-9.]+))@';

public static function discoverInterfaces() {
	$a = shell_exec( 'ifconfig -a' );
	preg_match_all( self::$ifconfigRegex, $a, $m);
	return $m[1];
}