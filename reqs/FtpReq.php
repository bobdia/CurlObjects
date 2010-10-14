<?php

/*
CURLOPT_FTP_USE_EPRT  	 TRUE to use EPRT (and LPRT) when doing active FTP downloads. Use FALSE to disable EPRT and LPRT and use PORT only.  	
CURLOPT_FTP_USE_EPSV 	TRUE to first try an EPSV command for FTP transfers before reverting back to PASV. Set to FALSE to disable EPSV. 	
CURLOPT_FTPAPPEND 	TRUE to append to the remote file instead of overwriting it. 	
CURLOPT_FTPASCII 	An alias of CURLOPT_TRANSFERTEXT. Use that instead. 	
CURLOPT_FTPLISTONLY 	TRUE to only list the names of an FTP directory. 
CURLOPT_TRANSFERTEXT  	 TRUE to use ASCII mode for FTP transfers. For LDAP, it retrieves data in plain text instead of HTML. On Windows systems, it will not set STDOUT to binary mode.
CURLOPT_UPLOAD  	 TRUE to prepare for an upload. 
CURLOPT_FTPSSLAUTH  	 The FTP authentication method (when is activated): CURLFTPAUTH_SSL (try SSL first), CURLFTPAUTH_TLS (try TLS first), or CURLFTPAUTH_DEFAULT (let cURL decide).
CURLOPT_FTPPORT  	 The value which will be used to get the IP address to use for the FTP "POST" instruction. The "POST" instruction tells the remote server to connect to our specified IP address. The string may be a plain IP address, a hostname, a network interface name (under Unix), or just a plain '-' to use the systems default IP address. 
CURLOPT_POSTQUOTE  	 An array of FTP commands to execute on the server after the FTP request has been performed.  	
CURLOPT_QUOTE 	An array of FTP commands to execute on the server prior to the FTP request. 
*/

class FtpRequest extends CurlRequest{

	public $options = array(
		CURLOPT_RETURNTRANSFER => 1
	);

}
?>