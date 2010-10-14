public static function parseForms($req){
	//$req can be html or HttpRequest Object
	if($req instanceof HttpRequest) $body = (string) $req->body;
	else $body = $req;

	$parser = new HtmlFormParser( $body );
	return $parser->parseForms();
}