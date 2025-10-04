<?php



$host = "localhost";
$dbname = "cms";
$db_user = "root";
$db_pass = "";



// Connect to DB
$conn = new mysqli($host, $db_user, $db_pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>