<?php
// index.php

// ==========================================
// 1. CONFIGURACIÓN Y CORS (Cross-Origin Resource Sharing)
// ==========================================
// --------------------------------------------------------------------------
// El navegador por seguridad bloquea peticiones entre dominios distintos.
// Como tu Vue está en un sitio y este PHP en otro (o puertos distintos),
// necesitamos dar permiso explícito con estas cabeceras.
// --------------------------------------------------------------------------

// Permite que CUALQUIER sitio web (*) nos pida datos.
header("Access-Control-Allow-Origin: *"); 

// Permite ciertos tipos de cabeceras y métodos HTTP.
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// IMPORTANTE: Permite el envío de cookies (necesario para mantener la sesión abierta).
header("Access-Control-Allow-Credentials: true"); 

// Le decimos al navegador que nuestra respuesta siempre será texto en formato JSON.
header("Content-Type: application/json; charset=UTF-8");

// --------------------------------------------------------------------------
// MANEJO DE PETICIONES "PRE-FLIGHT" (OPTIONS)
// Antes de hacer un POST o PUT, el navegador envía una petición de tipo "OPTIONS"
// para preguntar: "¿Tengo permiso para enviar datos?".
// Aquí respondemos "Sí (200 OK)" y cortamos la ejecución (exit).
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ==========================================
// 2. INICIO DE SISTEMAS
// ==========================================

// Iniciamos la "memoria" del servidor. Esto nos permite leer $_SESSION
// para saber si el usuario es Admin, quién es, etc.
session_start();

// Cargamos las herramientas necesarias:
require_once 'db.php';        // La conexión a la base de datos ($mysqli)
require_once 'functions.php'; // Toda la lógica (login, crearEvento, etc.)

// ==========================================
// 3. ENRUTAMIENTO (El Cerebro del API)
// ==========================================
// Capturamos la URL que viene del .htaccess (ej: midominio.com/backend/events/1)
// $_GET['url'] contendrá "events/1"
$url = isset($_GET['url']) ? $_GET['url'] : '';
$method = $_SERVER['REQUEST_METHOD']; // GET, POST, DELETE...

// Troceamos la URL usando la barra '/' como separador.
// Si la URL es: "events/5/signup"
// $parts[0] = "events" (El Recurso)
// $parts[1] = "5"      (El Parámetro o ID)
// $parts[2] = "signup" (La Acción específica)
$parts = explode('/', trim($url, '/'));
$resource = isset($parts[0]) ? $parts[0] : '';
$param    = isset($parts[1]) ? $parts[1] : null;



// ==========================================
// BLOQUE A: AUTENTICACIÓN (/auth)
// ==========================================
if ($resource === 'auth') {
    if ($method === 'POST') {
        // Vue envía los datos en formato JSON crudo, no como formulario normal.
        // Por eso usamos file_get_contents('php://input') para leerlo.
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Decidimos qué función llamar según la URL
        if ($param === 'login') login($mysqli, $input);
        elseif ($param === 'register') register($mysqli, $input);
        elseif ($param === 'logout') logout();
        else echo json_encode(["error" => "Acción de auth no válida"]);
    }
}

// ==========================================
// BLOQUE B: VIDEOJUEGOS (/games)
// ==========================================
elseif ($resource === 'games') {
    if ($method === 'GET') {
        if ($param) {
            // Si piden /games/5 -> Detalle de un juego (no implementado en tu front aún, pero preparado)
            echo json_encode(["mensaje" => "Detalle del juego " . $param]);
        } else {
            // Si piden /games?q=mario -> Búsqueda
            // Capturamos la variable 'q' de la URL (?q=...)
            $busqueda = isset($_GET['q']) ? $_GET['q'] : '';
            
            // Llamamos a la función que busca en la BD
            obtenerJuegosFiltrados($mysqli, $busqueda);
        }
    }
}

// ==========================================
// BLOQUE C: EVENTOS (/events)
// ==========================================
elseif ($resource === 'events') {
    
    // 1. LEER EVENTOS (Público - GET)
    // ----------------------------------------------------
    if ($method === 'GET') {
        if ($param) {
            echo json_encode(["mensaje" => "Detalle del evento " . $param]);
        } else {
            // Recogemos todos los filtros que envía Vue en la URL (?page=1&tipo=Taller...)
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
            $fecha = isset($_GET['fecha']) ? $_GET['fecha'] : '';
            $soloLibres = isset($_GET['soloLibres']) ? $_GET['soloLibres'] : '0';
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

            obtenerEventos($mysqli, $page, $tipo, $fecha, $soloLibres, $userId);
        }
    }
    
    // 2. CREAR EVENTO (Privado - POST)
    // ----------------------------------------------------
    // Entra aquí si es POST y NO tiene un tercer parámetro (ej: /events)
    elseif ($method === 'POST' && !isset($parts[2])) {
        
        // SEGURIDAD: Verificamos si hay sesión y si es ADMIN
        if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(["error" => "No autorizado"])); }
        if ($_SESSION['role'] !== 'ADMIN') { http_response_code(403); exit(json_encode(["error" => "Solo admins"])); }

        // MANEJO DE DATOS: 
        // Intentamos leer JSON por si acaso.
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Pero como al subir imágenes usamos FormData, el JSON vendrá vacío o nulo.
        // Si no es un array válido, lo dejamos vacío y la función 'crearEvento'
        // sabrá que tiene que buscar en $_POST y $_FILES.
        if (!is_array($input)) {
            $input = [];
        }

        crearEvento($mysqli, $input);
    }
    
    // 3. APUNTARSE / DESAPUNTARSE (/events/{id}/signup)
    // ----------------------------------------------------
    elseif (isset($parts[2]) && $parts[2] === 'signup') {
        // Seguridad: Solo usuarios logueados
        if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(["error" => "Debes iniciar sesión"])); }
        
        $eventId = (int)$param;       // El ID del evento (viene de la URL)
        $userId = $_SESSION['user_id']; // El ID del usuario (viene de la sesión/cookie)

        // DETECTAR SI ES BORRAR:
        // A veces los servidores bloquean el método DELETE.
        // Permitimos simular un DELETE enviando POST con ?action=delete en la URL.
        $esBorrar = ($method === 'DELETE') || ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete');

        if ($esBorrar) {
            desapuntarseEvento($mysqli, $eventId, $userId);
        } 
        elseif ($method === 'POST') {
            inscribirseEvento($mysqli, $eventId, $userId);
        }
    }
}

// ==========================================
// BLOQUE D: USUARIO (/users)
// ==========================================
elseif ($resource === 'users' && $param === 'me') {
    // Seguridad: Necesitas estar logueado para ver "tus" datos
    if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(["error" => "No autorizado"])); }

    $subaction = isset($parts[2]) ? $parts[2] : null;

    // Ruta: /users/me/events -> Devuelve los eventos a los que me he apuntado
    if ($method === 'GET' && $subaction === 'events') {
        obtenerMisEventos($mysqli, $_SESSION['user_id']);
    } 
    // Ruta: /users/me -> Devuelve mis datos básicos (para saber si sigo logueado al refrescar)
    elseif ($method === 'GET') {
        echo json_encode([
            "authenticated" => true,
            "username" => $_SESSION['username'],
            "role" => $_SESSION['role']
        ]);
    }
} else {
    // Si llegamos aquí, la URL no coincide con nada conocido (Error 404)
    http_response_code(404);
    echo json_encode(["error" => "Ruta no encontrada"]);
}
?>