<?php

include '../CurlObjects.php';
include '../debug/krumo/class.krumo.php';

function p($req) {
	
	echo htmlspecialchars($req->body);

}

$h = new HttpReq('http://example.org/');

$h->attach('success', 'p');


$h->exec();

krumo($h);

?>