<?php

$host = "localhost"; // Hostinger always uses localhost for MySQL
$user = "u916312019_joseph";   // your actual MySQL username
$pass = "Twice_jihyo12345";         // the password you set
$db   = "u916312019_cms"; 

// Connect to DB
$conn = new mysqli($host, $db_user, $db_pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>