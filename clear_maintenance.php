<?php

$conn = new mysqli('localhost', 'moodle', 'moodle', 'moodle');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "DELETE FROM mdl_config WHERE name='upgraderunning'";
if ($conn->query($sql) === TRUE) {
    echo "Record deleted successfully\n";
} else {
    echo "Error deleting record: " . $conn->error . "\n";
}

$conn->close();
