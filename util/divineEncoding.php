/* Resources
 * http://nikitathespider.com/articles/EncodingDivination.html
 * http://www.w3.org/TR/REC-xml/#sec-guessing
 * http://en.wikipedia.org/wiki/Byte_Order_Mark
 */

static public function divineEncoding($content, $typeHeader = null) {
	$found = array();
	if($typeHeader != null) {
		if(strpos($typeHeader, ';') !== false) {
			$cTypes = explode(';', $typeHeader);
			$contentType = $cTypes[0];
			if(isset($cTypes[1]) && strpos($cTypes[1], '=') !== false) {
				$charset = explode('=', $cTypes[1]);
				if($charset[0] == 'charset' && isset($charset[1]) && !empty($charset[1]) {
					$found['header'] = $charset[1];
				}
			}
		} else {
			$contentType = $typeHeader;
		}
		$found['contentType'] = $contentType;
	}
	
	if($contentType) {
		switch($contentType) {
		case 'text/xml':
			$found['default'] = 'US-ASCII';
			break;
		case 'text/plain':
			$found['default']  = 'ISO-8859-1';
			break;
		case 'text/html':
			$found['default']  = 'ISO-8859-1';
			break;
		}
	}
	
	$boms = array(
		'UTF-8' => "\xEF\xBB\xBF",
		'UTF-1' => "\xF7\x64\x4C",
		'UTF-7' => "\x2B\x2F\x76",
		'UTF-16BE' => "\xFE\xFF",
		'UTF-16LE' => "\xFF\xFE",
		'UTF-32BE' => "\x00\x00\xFE\xFF",
		'UTF-32LE' => "\xFF\xFE\x00\x00",
		'UTF-32-2143' => "\x00\x00\xFF\xFE",
		'UTF-32-3412' => "\xFE\xFF\x00\x00",
		'UTF-EBCDIC' => "\xDD\x73\x66\x73",
		'SCSU' => "\x0E\xFE\xFF",
		'BOCU-1' => "\xFB\xEE\x28",
		'GB-18030' => "\x84\x31\x95\x33"
		);
	$foundBom = false;
	foreach($boms as $enc => $bom) {
		if(strncmp($content, $bom, strlen($bom)) === 0) {
			$found['bom'] = $enc;
		}
	}
	
	if(preg_match('#<meta http-equiv="Content-Type" content="text/html; charset=utf-8">#', $content, $metaMatch)) {
		$found['meta'] = $meta_enc;
	}
	
	if(preg_match('#<meta charset="utf-8">#', $content, $metaMatch)) {
		$found['meta'] = $meta_enc;
	}
	
	
	if(preg_match('#<?xml[^>]+encoding=["\']([^"\']+)["\'][^>]+>#', $content, $xmlMatch)) {
		$found['xml'] = $xmlMatch[1];
	}
	
	return $defaultCharset;
}