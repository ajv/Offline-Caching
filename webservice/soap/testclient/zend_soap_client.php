<?php
/**
 * Moodle - Modular Object-Oriented Dynamic Learning Environment
 *         http://moodle.com
 *
 * LICENSE
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details:
 *
 *         http://www.gnu.org/copyleft/gpl.html
 *
 * @category  Moodle
 * @package   webservice
 * @copyright Copyright (c) 1999 onwards Martin Dougiamas     http://dougiamas.com
 * @license   http://www.gnu.org/copyleft/gpl.html     GNU GPL License
 */


/*
 * Zend Soap sclient
 */

require_once('../../../config.php');
require_once('../lib.php');
include "Zend/Loader.php";
Zend_Loader::registerAutoload();

///Display Moodle page header
print_header('Soap test client', 'Soap test client'.":", true);

/// check that webservices are enable into your Moodle
/// WARNING: it makes sens here only because this client runs on the same machine as the 
///          server, if you change the WSDL url, please comment the following if statement
if (!webservice_lib::display_webservices_availability("soap")) {
    echo "<br/><br/>";
    echo "Please fix the previous problem(s), the testing session has been interupted.";
    echo $OUTPUT->footer();
    exit();
}

/// authenticate => get a conversation token from the server
/// You need a wsuser/wspassword user in the remote Moodle
$client = new Zend_Soap_Client($CFG->wwwroot."/webservice/soap/server.php?wsdl");
try {
    $token = $client->get_token(array('username' => "wsuser", 'password' => "wspassword"));
    print "<pre>\n";
     print "<br><br><strong>Token: </strong>".$token;
    print "</pre>";
} catch (exception $exception) {
    print "<br><br><strong>An exception occured during authentication: \n</strong>";
    print "<pre>\n";
    print $exception;
    print "</pre>";
    printLastRequestResponse($client);
}

/// Following some code in order to print the WSDL into the end of the source code page
/// Change the classpath to get specific service
/*
    $client = new Zend_Http_Client($CFG->wwwroot."/webservice/soap/server.php?token=".$token."&classpath=user&wsdl", array(
        'maxredirects' => 0,
        'timeout'      => 30));
    $response = $client->request();
    $wsdl = $response->getBody();
    print $wsdl;
    exit();
 *
 */
 

/// build the Zend SOAP client from the remote WSDL
$client = new Zend_Soap_Client($CFG->wwwroot."/webservice/soap/server.php?token=".$token."&classpath=user&wsdl");
print "<br><br><strong>You are accessing the WSDL: </strong>";
print "<br><br>".$CFG->wwwroot."/webservice/soap/server.php?token=".$token."&classpath=user&wsdl<br>";

/// Get any user with string "admin"
print "<br><br><strong>Get users:</strong>";
print "<pre>\n";
try {
    var_dump($client->get_users(array('search' => "admin")));
} catch (exception $exception) {
    print $exception;
    print "<br><br><strong>An exception occured: \n</strong>";
    printLastRequestResponse($client);
}
print "</pre>";

/// Create a user with "mockuser66" username
print "<br><br><strong>Create user:</strong>";
print "<pre>\n";
try {
    var_dump($client->create_users(array(array('username' => "mockuser66",'firstname' => "firstname6",'lastname' => "lastname6",'email' => "mockuser6@mockuser6.com",'password' => "password6"))));
} catch (exception $exception) {
    print $exception;
    print "<br><br><strong>An exception occured: \n</strong>";
    printLastRequestResponse($client);
}
print "</pre>";

/// Update this user
print "<br><br><strong>Update user:</strong>";
print "<pre>\n";
try {
    var_dump($client->update_users(array(array('username' => "mockuser66",'newusername' => "mockuser6b",'firstname' => "firstname6b"))));
} catch (exception $exception) {
    print $exception;
    print "<br><br><strong>An exception occured: \n</strong>";
    printLastRequestResponse($client);
}
print "</pre>";

/// Delete this user
print "<br><br><strong>Delete user:</strong>";
print "<pre>\n";
try {
    var_dump($client->delete_users(array(array('username' => "mockuser6b"))));
} catch (exception $exception) {
    print $exception;
    print "<br><br><strong>An exception occured: \n</strong>";
    printLastRequestResponse($client);
}
print "</pre>";

/// Display Moodle page footer
echo $OUTPUT->footer();


/**
 * Display the last request
 * @param <type> $client
 */
function printLastRequestResponse($client) {
    print "<pre>\n";
    print "Request :\n".htmlspecialchars($client->__getLastRequest()) ."\n";
    print "Response:\n".htmlspecialchars($client->__getLastResponse())."\n";
    print "</pre>";
}

?>
