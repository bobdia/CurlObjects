public static function vars($vars,$raw=false,$sep='&',$arraySuffix='[]') {
	$str = '';
	foreach( $vars as $k => $v) {
		if(is_array($v)) {
			foreach($v as $vi) {
				$str .= (($raw)?rawurlencode($k):urlencode($k)).$arraySuffix.'='.(($raw)?rawurlencode($vi):urlencode($vi)).$sep;
			}
		} else {
			$str .= (($raw)?rawurlencode($k):urlencode($k)).'='.(($raw)?rawurlencode($v):urlencode($v)).$sep;
		}
	}
	return substr($str, 0, -1);
}