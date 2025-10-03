<?php
$host = "localhost"; // Hostinger always uses localhost for MySQL
$user = "u916312019_cmsdb";   // your actual MySQL username
$pass = "Mercelyn1";         // the password you set
$db   = "u916312019_cms";     // your actual database name

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
