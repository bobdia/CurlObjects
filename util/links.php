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