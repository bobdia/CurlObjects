<?php


function debug_http($r) {
	$data = array();
	$data['head'] =  $r->head;	
	$data['body'] = $r->body;
	
	$data['headers'] = print_r($r->headers,true);

	$data['method'] = $r->method;
	$data['args'] = print_r($r->args,true);
	$data['stringPost'] = $r->stringPost;
	$data['argsSeparator'] = $r->argsSeparator;
	$data['argsArraySeparator'] = $r->argsArraySeparator;
	$data['rawUrlEncode'] = $r->rawUrlEncode;
	
	$data['cookies'] = print_r($r->cookies,true);
	$data['cookieFile'] = $r->cookieFile;
	
	$data['totalTime'] = $r->totalTime;
	$data['lastURL'] = $r->lastURL;
	$data['status'] = $r->status;
	
	return json_encode($data);
}

?>