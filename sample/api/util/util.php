<?php

/**
 * @author: Anjaneyulu Reddy BEERAVALLI <anji.reddy@knolskape.com>
 *
 * @since: Wed 30 Jan, 2013
 * @file: util.php
 *
 * @copyright: KNOLSKAPE Solutions Pvt Ltd
 **/

 /**
 * FILE DESCRIPTION
 *
 **/

function get_user_id ()
{	
	//return $_SESSION['user_id'];

	/*Change this code after user-management implementation*/
	return $_SESSION ? $_SESSION['user_id'] : $_SESSION['user_id']=14;
}

function get_base ()
{
	$instance = \Slim\Slim::getInstance();
	$base['request'] = $instance->request();
	$base['con'] = Helpers::pdo_db_connect();
	$request = $base['request'];
	
	/**
	 * getBody() method does not return parameters sent by a GET request.
	 * passing second parameter `true` to json_decode will return an associative array
	 **/
	if ($request->isGet()) {
		$base['req_params'] = $request->get();
	}
	else {
		$base['req_params'] = json_decode($request->getBody(), true);
	}

	return $base;
}

?>