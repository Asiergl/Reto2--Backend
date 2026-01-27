<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

$url = isset($_GET['url']) ? $_GET['url'] : '';
$method = $_SERVER['REQUEST_METHOD'];

$parts = explode('/', trim($url, '/'));
$resource = isset($parts[0]) ? $parts[0] : '';

if ($resource === 'games' && $method === 'GET') {
    // Capturamos los tres posibles filtros
    $titulo = isset($_GET['s']) ? $_GET['s'] : '';
    $genero = isset($_GET['g']) ? $_GET['g'] : '';
    $plataforma = isset($_GET['p']) ? $_GET['p'] : '';

    // Si no hay ningún filtro, obtenemos todos, si hay alguno, filtramos de forma combinada
    if (empty($titulo) && empty($genero) && empty($plataforma)) {
        obtenerJuegos($mysqli);
    } else {
        buscarJuegos($titulo, $genero, $plataforma, $mysqli);
    }
} else {
    http_response_code(404);
    echo json_encode(["error" => "Recurso no encontrado"]);
}

function obtenerJuegos($mysqli) {
    $sql = "SELECT * FROM games";
    $result = $mysqli->query($sql);
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    foreach ($data as &$juego) {
        if (isset($juego['plataformas'])) {
            $juego['plataformas'] = json_decode($juego['plataformas']);
        }
    }
    echo json_encode($data);
}

function buscarJuegos($titulo, $genero, $plataforma, $mysqli) {
    $sql = "SELECT * FROM games WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($titulo)) {
        $sql .= " AND titulo LIKE ?";
        $params[] = "%" . $titulo . "%";
        $types .= "s";
    }
    if (!empty($genero)) {
        $sql .= " AND genero LIKE ?";
        $params[] = "%" . $genero . "%";
        $types .= "s";
    }
    if (!empty($plataforma)) {
        $sql .= " AND plataformas LIKE ?";
        $params[] = "%" . $plataforma . "%";
        $types .= "s";
    }

    $stmt = $mysqli->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    foreach ($data as &$juego) {
        if (isset($juego['plataformas'])) {
            $juego['plataformas'] = json_decode($juego['plataformas']);
        }
    }
    echo json_encode($data);
}
?>