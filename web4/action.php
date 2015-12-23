<?php
$filename = date('YmdHis') . '.jpg';
$result = file_put_contents( 'pics/'.$filename, file_get_contents('php://input') );
if (!$result) {
	print "ERROR: Failed to write data to $filename, check permissions\n";
	exit();
}
$url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/pics/' . $filename;
print "$url\n";
?>