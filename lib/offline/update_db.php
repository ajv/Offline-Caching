<?php 

require_once('../../config.php');

$log = $_POST;
$result = $DB->insert_record_raw('log', $log, false);
print_r($log);

if ($result) {
	print_r($result);
}
else {
    print_r('Error: Could not insert a new entry to the Moodle log during offline synchronization');
}

?>