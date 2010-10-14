<?php
/*
Makes CurlObjects.php
*/

$a[] = substr(file_get_contents('../CurlBase.php'), 5, -2);


include_once 'makeUtils.php';



$a[] = substr(file_get_contents('../reqs/CurlReq.php'), 5, -2);
$a[] = substr(file_get_contents('../reqs/HttpReq.php'), 5, -2);
$a[] = substr(file_get_contents('../reqs/StatefulReq.php'), 5, -2);
//$a[] = substr(file_get_contents('./reqs/FtpReq.php'), 5, -2);
$a[] = substr(file_get_contents('../CurlUtil.php'), 5, -2);
$at = file_get_contents('../LICENSE');

$s = implode("\n\n", $a);

$s = '<?php'."\n".$at."\n".$s."\n".'?>';

file_put_contents('../CurlObjects.php',$s);

echo 'Wrote file to /CurlObjects.php';

?>