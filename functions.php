<?php
// functions.php

// ==========================================
// FUNCIONES DE AUTENTICACIÓN
// ==========================================

function login($mysqli, $input) {
    if (!isset($input['email']) || !isset($input['password'])) { 
        http_response_code(400); return; 
    }
    
    $stmt = $mysqli->prepare("SELECT id, username, password_hash, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $input['email']);
    $stmt->execute();
    
    // REQUISITO PROFESOR: Obtener objeto recurso
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($input['password'], $row['password_hash'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            echo json_encode(["message" => "Login exitoso", "role" => $row['role'], "username" => $row['username']]);
        } else {
            http_response_code(401); echo json_encode(["error" => "Contraseña incorrecta"]);
        }
    } else {
        http_response_code(404); echo json_encode(["error" => "Usuario no encontrado"]);
    }
}

function register($mysqli, $input) {
    if (!isset($input['username']) || !isset($input['email']) || !isset($input['password'])) { 
        http_response_code(400); return; 
    }
    $hash = password_hash($input['password'], PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'USER')");
    $stmt->bind_param("sss", $input['username'], $input['email'], $hash);
    
    if ($stmt->execute()) echo json_encode(["message" => "Registrado correctamente"]);
    else { http_response_code(500); echo json_encode(["error" => "Error al registrar"]); }
}

function logout() {
    session_destroy();
    echo json_encode(["message" => "Sesión cerrada"]);
}

// ==========================================
// FUNCIONES DE DATOS (API PÚBLICA / PRIVADA)
// ==========================================

function obtenerJuegosFiltrados($mysqli, $titulo, $genero, $plataforma) {
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
    
    // REQUISITO PROFESOR: get_result() y fetch_all()
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    // Decodificar JSON de plataformas para el frontend
    foreach ($data as &$juego) {
        if (isset($juego['plataformas'])) {
            $decoded = json_decode($juego['plataformas']);
            if (json_last_error() === JSON_ERROR_NONE) {
                $juego['plataformas'] = $decoded;
            }
        }
    }

    // REQUISITO PROFESOR: Devolver JSON UTF8
    echo json_encode($data);
}

function obtenerEventos($mysqli, $page, $tipo, $fecha, $soloLibres, $userId = null) {
    if ($page < 1) $page = 1;
    $limit = 9; 
    $offset = ($page - 1) * $limit;

    $sql = "SELECT * FROM events WHERE 1=1";
    $params = [];
    $types = "";

    if ($tipo !== 'todos' && !empty($tipo)) { $sql .= " AND tipo = ?"; $params[] = $tipo; $types .= "s"; }
    if (!empty($fecha)) { $sql .= " AND fecha = ?"; $params[] = $fecha; $types .= "s"; }
    if ($soloLibres === '1' || $soloLibres === 'true') { $sql .= " AND plazasLibres > 0"; }

    $sql .= " ORDER BY fecha ASC, hora ASC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= "ii";

    $stmt = $mysqli->prepare($sql);
    if (!empty($types)) { $stmt->bind_param($types, ...$params); }

    $stmt->execute();
    
    // REQUISITO PROFESOR: get_result() y fetch_all()
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode($data);
}

function inscribirseEvento($mysqli, $eventId, $userId) {
    $check = $mysqli->prepare("SELECT * FROM user_events WHERE user_id = ? AND event_id = ?");
    $check->bind_param("ii", $userId, $eventId);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) { 
        http_response_code(409); echo json_encode(["error" => "Ya inscrito"]); return; 
    }

    $mysqli->begin_transaction();
    try {
        $stmt2 = $mysqli->prepare("UPDATE events SET plazasLibres = plazasLibres - 1 WHERE id = ? AND plazasLibres > 0");
        $stmt2->bind_param("i", $eventId);
        $stmt2->execute();
        if ($stmt2->affected_rows === 0) throw new Exception("Sin plazas");

        $stmt1 = $mysqli->prepare("INSERT INTO user_events (user_id, event_id) VALUES (?, ?)");
        $stmt1->bind_param("ii", $userId, $eventId);
        $stmt1->execute();

        $mysqli->commit();
        echo json_encode(["message" => "Inscrito con éxito"]);
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(400); echo json_encode(["error" => $e->getMessage()]);
    }
}

function desapuntarseEvento($mysqli, $eventId, $userId) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli->begin_transaction();
    try {
        $stmt1 = $mysqli->prepare("DELETE FROM user_events WHERE user_id = ? AND event_id = ?");
        $stmt1->bind_param("ii", $userId, $eventId);
        $stmt1->execute();

        if ($stmt1->affected_rows > 0) {
            $stmt2 = $mysqli->prepare("UPDATE events SET plazasLibres = plazasLibres + 1 WHERE id = ?");
            $stmt2->bind_param("i", $eventId);
            $stmt2->execute();
        }

        $mysqli->commit();
        echo json_encode(["message" => "Desapuntado"]);
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(500); 
        echo json_encode(["error" => "Fallo SQL: " . $e->getMessage()]);
    }
}

function obtenerMisEventos($mysqli, $userId) {
    $sql = "SELECT e.* FROM events e JOIN user_events ue ON e.id = ue.event_id WHERE ue.user_id = ? ORDER BY e.fecha ASC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    // REQUISITO PROFESOR: get_result() y fetch_all()
    $result = $stmt->get_result();
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
}

function crearEvento($mysqli, $input) {
    $imagen = !empty($input['imagen']) ? $input['imagen'] : 'default.png'; 
    $stmt = $mysqli->prepare("INSERT INTO events (titulo, tipo, fecha, hora, plazasLibres, imagen, descripcion, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssissi", $input['titulo'], $input['tipo'], $input['fecha'], $input['hora'], $input['plazasLibres'], $imagen, $input['descripcion'], $_SESSION['user_id']);
    
    if ($stmt->execute()) echo json_encode(["message" => "Creado"]);
    else { http_response_code(500); echo json_encode(["error" => "Error"]); }
}
?>