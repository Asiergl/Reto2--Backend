<?php
// db.php
$host = 'localhost'; // O el nombre del servicio en Docker (ej: 'db')
$db   = 'gamefest';
$user = 'root';      // O tu usuario configurado
$pass = '';          // O tu contraseña configurada

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die("Error de conexión: " . $mysqli->connect_error);
}

// Forzar UTF-8 para no tener problemas con acentos
$mysqli->set_charset("utf8mb4");
?>