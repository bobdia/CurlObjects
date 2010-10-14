<?php

include '../CurlObjects.php';
include '../debug/krumo/class.krumo.php';

function p($req) {
	
	echo 'Success! -> '.htmlspecialchars($req->body);

}

$h = new HttpReq('http://example.org/', array(CURLINFO_HEADER_OUT=>true));
$h2 = new HttpReq('http://example.org/', array(CURLINFO_HEADER_OUT=>true));

$h->logEvents = true;
$h2->logEvents = true;

$h->attach('success', 'p');

$c = new CurlBase;

$c->add($h);
$c->add($h2);

$c->perform();

krumo($c);

?>