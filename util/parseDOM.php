public static function parseDOM($obj){
	//$obj can be html or HttpRequest Object
	if($obj instanceof HttpRequest) $str = (string) $obj->body;
	else $str = $obj;
	$dom = new DOMDocument;
	return $dom->loadHTML($str);
}