public static function varsToArray($str){
	$arr = array();
	parse_str($str, $arr);
	return $arr;
}