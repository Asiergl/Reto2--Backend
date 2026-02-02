<?php
// index.php

// ==========================================
// 1. CONFIGURACIÓN Y CORS
// ==========================================
// Permite acceso desde cualquier origen (necesario para el despliegue)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Credentials: true"); 
header("Content-Type: application/json; charset=UTF-8");

// Manejo de la petición OPTIONS (Pre-flight para CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// INICIO DE SESIÓN (Requisito para modificar datos)
session_start();

// IMPORTACIONES
require_once 'db.php';
require_once 'functions.php'; // Aquí están las funciones separadas

// ==========================================
// 2. ENRUTAMIENTO (Router)
// ==========================================
$url = isset($_GET['url']) ? $_GET['url'] : '';
$method = $_SERVER['REQUEST_METHOD'];

$parts = explode('/', trim($url, '/'));
$resource = isset($parts[0]) ? $parts[0] : '';
$param    = isset($parts[1]) ? $parts[1] : null;

// --- BLOQUE A: AUTENTICACIÓN ---
if ($resource === 'auth') {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($param === 'login') login($mysqli, $input);
        elseif ($param === 'register') register($mysqli, $input);
        elseif ($param === 'logout') logout();
        else echo json_encode(["error" => "Acción de auth no válida"]);
    }
}

// --- BLOQUE B: VIDEOJUEGOS ---
elseif ($resource === 'games') {
    if ($method === 'GET') {
        if ($param) {
            echo json_encode(["mensaje" => "Detalle del juego " . $param]);
        } else {
            // Filtros URL
            $titulo = isset($_GET['s']) ? $_GET['s'] : '';
            $genero = isset($_GET['g']) ? $_GET['g'] : '';
            $plataforma = isset($_GET['p']) ? $_GET['p'] : '';
            
            obtenerJuegosFiltrados($mysqli, $titulo, $genero, $plataforma);
        }
    }
}

// --- BLOQUE C: EVENTOS ---
elseif ($resource === 'events') {
    // 1. GET EVENTOS (Público)
    if ($method === 'GET') {
        if ($param) {
            echo json_encode(["mensaje" => "Detalle del evento " . $param]);
        } else {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
            $fecha = isset($_GET['fecha']) ? $_GET['fecha'] : '';
            $soloLibres = isset($_GET['soloLibres']) ? $_GET['soloLibres'] : '0';
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

            obtenerEventos($mysqli, $page, $tipo, $fecha, $soloLibres, $userId);
        }
    }
    // 2. CREAR EVENTO (Privado - Requiere ADMIN)
    elseif ($method === 'POST' && !isset($parts[2])) {
        if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(["error" => "No autorizado"])); }
        if ($_SESSION['role'] !== 'ADMIN') { http_response_code(403); exit(json_encode(["error" => "Solo admins"])); }

        $input = json_decode(file_get_contents('php://input'), true);
        crearEvento($mysqli, $input);
    }
    // 3. INSCRIPCIÓN / DESAPUNTARSE (Privado - Requiere Login)
    elseif (isset($parts[2]) && $parts[2] === 'signup') {
        if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(["error" => "Debes iniciar sesión"])); }
        
        $eventId = (int)$param;
        $userId = $_SESSION['user_id'];

        // Truco para servidores que bloquean DELETE
        $esBorrar = ($method === 'DELETE') || ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete');

        if ($esBorrar) {
            desapuntarseEvento($mysqli, $eventId, $userId);
        } 
        elseif ($method === 'POST') {
            inscribirseEvento($mysqli, $eventId, $userId);
        }
    }
}

// --- BLOQUE D: USUARIO ---
elseif ($resource === 'users' && $param === 'me') {
    if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(["error" => "No autorizado"])); }

    $subaction = isset($parts[2]) ? $parts[2] : null;

    if ($method === 'GET' && $subaction === 'events') {
        obtenerMisEventos($mysqli, $_SESSION['user_id']);
    } elseif ($method === 'GET') {
        echo json_encode([
            "authenticated" => true,
            "username" => $_SESSION['username'],
            "role" => $_SESSION['role']
        ]);
    }
} else {
    http_response_code(404);
    echo json_encode(["error" => "Ruta no encontrada"]);
}
?>