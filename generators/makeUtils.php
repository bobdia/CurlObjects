<?php


function makeUtils() {
$path = '../util/';
$c = array();
$u = array();
$loaded = array();

$activeUtils = array(
	'profile' => 0,
	'varsToArray' => 0,
	'discoverInterfaces' => 0,
	'links' => array( 'utils' => array('resolveUrl')),
	'resolveUrl' => array( 'utils' => array('glueUrl')),
	'glueUrl' => 0,
	);


foreach($activeUtils as $k=>$a) {
	if(!isset($loaded[$k])) {
	$loaded[$k] = 1;
	$p[] = file_get_contents($path.$k.'.php');
	if(is_array($a)) {
		if(isset($a['utils'])) {
			foreach($a['utils'] as $u) {
				if(!isset($loaded[$u])) {
				$loaded[$u] = 1;
				$p[] = file_get_contents($path.$u.'.php');
				}
			}
		}
		if(isset($a['classes'])) {
			foreach($a['classes'] as $c) {
				if(!isset($loaded[$c])) {
					$loaded[$c] = 1;
					$cl[] = substr(file_get_contents($path.'classes/'.$c.'.php'), 5,-2);
				}
			}
		}
	}
	}

}

$p = implode("\n\n", $p);
if(!empty($cl))
	$cl = implode("\n\n", $cl);

$s = '<?php';
$s .= "\n\n";
$s .= "class CurlUtil {\n";
$s .= "$p\n";
$s .= "}\n\n";
if(is_string($cl))
	$s .= "$cl\n\n";
$s .= '?>';

file_put_contents('../CurlUtil.php', $s);
}

makeUtils();
?>