<?php
/**
 * Created on 10/17/2008
 *
 * Rest Test Client
 *
 * @author David Castro Garcia
 * @author Ferran Recio Calderó
 * @author Jerome Mouneyrac
 */

require_once ('config_rest.php');
start_interface("Create A User");

$ch = curl_init();

$data['user:username'] = "mockuser4";
$data['user:firstname'] = "mockuser4";
$data['user:lastname'] = "mockuser4";
$data['user:email'] = "mockuser4@lastname.com";

var_dump($data);

curl_setopt($ch, CURLOPT_URL, $CFG->serverurl.'/user/tmp_create_user');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, format_postdata($data));

$out = curl_exec($ch);

$res = basicxml_xml_to_object($out);

show_object($res->userid);

show_xml ($out);

end_interface();
?>