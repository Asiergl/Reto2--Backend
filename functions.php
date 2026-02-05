<?php
// functions.php

// =========================================================================
// BLOQUE 1: SISTEMA DE AUTENTICACIÓN (LOGIN, REGISTRO, LOGOUT)
// =========================================================================

// --- INICIO DE SESIÓN ---
function login($mysqli, $input) {
    // 1. Verificamos que nos hayan enviado email y password.
    if (!isset($input['email']) || !isset($input['password'])) { 
        http_response_code(400); return; 
    }
    
    // 2. Buscamos el usuario por su email.
    // Usamos 'prepare' y 'bind_param' para evitar inyección SQL (hackeo básico).
    $stmt = $mysqli->prepare("SELECT id, username, password_hash, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $input['email']);
    $stmt->execute();
    
    // Obtenemos el resultado de la base de datos.
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // 3. Verificamos la contraseña.
        // password_verify compara el texto plano ('1234') con el hash encriptado de la BD.
        if (password_verify($input['password'], $row['password_hash'])) {
            // ¡Éxito! Guardamos los datos clave en la SESIÓN del servidor.
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role']; // Importante para saber si es ADMIN o USER
            
            // Devolvemos al frontend los datos para que actualice la interfaz.
            echo json_encode(["message" => "Login exitoso", "role" => $row['role'], "username" => $row['username']]);
        } else {
            http_response_code(401); echo json_encode(["error" => "Contraseña incorrecta"]);
        }
    } else {
        http_response_code(404); echo json_encode(["error" => "Usuario no encontrado"]);
    }
}

// --- REGISTRO DE NUEVOS USUARIOS ---
function register($mysqli, $input) {
    // 1. Validación: Nos aseguramos de que no haya campos vacíos.
    if (empty($input['username']) || empty($input['email']) || empty($input['password'])) { 
        http_response_code(400); 
        echo json_encode(["error" => "Rellena todos los campos"]);
        return; 
    }

    // 2. Anti-Duplicados: Comprobamos si el email ya existe.
    $check = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $input['email']);
    $check->execute();
    $check->store_result(); // Guardamos el resultado en memoria para poder contar filas.

    if ($check->num_rows > 0) {
        http_response_code(409); // 409 Conflict
        echo json_encode(["error" => "Este correo electrónico ya está registrado"]);
        return;
    }
    $check->close();

    // 3. Creación: Encriptamos la contraseña y guardamos.
    $hash = password_hash($input['password'], PASSWORD_BCRYPT);
    
    // Por defecto, todos los nuevos son 'USER' (no ADMIN).
    $stmt = $mysqli->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'USER')");
    $stmt->bind_param("sss", $input['username'], $input['email'], $hash);
    
    if ($stmt->execute()) {
        echo json_encode(["message" => "Registrado correctamente"]);
    } else { 
        http_response_code(500); 
        echo json_encode(["error" => "Error al registrar en la base de datos"]); 
    }
}

// --- CERRAR SESIÓN ---
function logout() {
    // Simplemente destruimos la sesión en el servidor.
    // Nota: El frontend debe encargarse de borrar la cookie si es necesario.
    session_destroy();
    echo json_encode(["message" => "Sesión cerrada"]);
}

// =========================================================================
// BLOQUE 2: CATÁLOGO DE JUEGOS (Búsqueda y Filtros)
// =========================================================================

function obtenerJuegosFiltrados($mysqli, $busqueda) {
    // Limpieza: Quitamos espacios al inicio y final ("  mario  " -> "mario")
    $busqueda = trim($busqueda);

    $sql = "SELECT * FROM games WHERE 1=1"; // Truco SQL: 1=1 permite añadir 'AND' después sin preocuparse.
    $params = [];
    $types = "";

    if (!empty($busqueda)) {
        // FILTRO POTENTE: Usamos 'COLLATE utf8mb4_unicode_ci' para ignorar acentos y mayúsculas.
        // Buscamos coincidencia parcial (LIKE %...%) en Título, Género o Plataforma.
        $sql .= " AND (
            titulo COLLATE utf8mb4_unicode_ci LIKE ? OR 
            genero COLLATE utf8mb4_unicode_ci LIKE ? OR 
            plataformas COLLATE utf8mb4_unicode_ci LIKE ?
        )";
        
        $parametro = "%" . $busqueda . "%";
        
        // Añadimos el mismo término 3 veces (uno por cada ?)
        $params[] = $parametro;
        $params[] = $parametro;
        $params[] = $parametro;
        $types .= "sss"; // 3 strings
    }

    $stmt = $mysqli->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    // DECODIFICACIÓN JSON:
    // En la BD, las plataformas se guardan como texto '["PC", "PS5"]'.
    // Aquí lo convertimos de texto a Array real de PHP para enviarlo limpio al frontend.
    foreach ($data as &$juego) {
        if (isset($juego['plataformas'])) {
            $decoded = json_decode($juego['plataformas']);
            if (json_last_error() === JSON_ERROR_NONE) {
                $juego['plataformas'] = $decoded;
            }
        }
    }

    echo json_encode($data);
}

// =========================================================================
// BLOQUE 3: GESTIÓN DE EVENTOS (Listar, Crear, Inscribirse)
// =========================================================================

// --- LISTADO PÚBLICO DE EVENTOS ---
function obtenerEventos($mysqli, $page, $tipo, $fecha, $soloLibres, $userId = null) {
    // Paginación: Calculamos desde qué fila empezar a leer.
    if ($page < 1) $page = 1;
    $limit = 9; // Mostramos 9 eventos por página
    $offset = ($page - 1) * $limit;

    $sql = "SELECT * FROM events WHERE 1=1";
    $params = [];
    $types = "";

    // Aplicamos filtros dinámicos según lo que el usuario haya seleccionado.
    if ($tipo !== 'todos' && !empty($tipo)) { $sql .= " AND tipo = ?"; $params[] = $tipo; $types .= "s"; }
    if (!empty($fecha)) { $sql .= " AND fecha = ?"; $params[] = $fecha; $types .= "s"; }
    if ($soloLibres === '1' || $soloLibres === 'true') { $sql .= " AND plazasLibres > 0"; }

    // Ordenamos por fecha y aplicamos la paginación.
    $sql .= " ORDER BY fecha ASC, hora ASC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= "ii"; // dos enteros (integers)

    $stmt = $mysqli->prepare($sql);
    if (!empty($types)) { $stmt->bind_param($types, ...$params); }

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode($data);
}

// --- APUNTARSE A UN EVENTO ---
function inscribirseEvento($mysqli, $eventId, $userId) {
    // 1. Verificar si ya está apuntado para no duplicar.
    $check = $mysqli->prepare("SELECT * FROM user_events WHERE user_id = ? AND event_id = ?");
    $check->bind_param("ii", $userId, $eventId);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) { 
        http_response_code(409); echo json_encode(["error" => "Ya estas inscrito al evento"]); return; 
    }

    // 2. TRANSACCIÓN: Esto es vital.
    // Necesitamos hacer dos cosas a la vez: Restar una plaza Y Crear la inscripción.
    // Si una falla, la otra debe cancelarse. Por eso usamos begin_transaction().
    $mysqli->begin_transaction();
    try {
        // A. Restamos una plaza (Solo si hay plazas libres > 0)
        $stmt2 = $mysqli->prepare("UPDATE events SET plazasLibres = plazasLibres - 1 WHERE id = ? AND plazasLibres > 0");
        $stmt2->bind_param("i", $eventId);
        $stmt2->execute();
        
        // Si no se actualizó ninguna fila, es que no había plazas. Lanzamos error.
        if ($stmt2->affected_rows === 0) throw new Exception("Sin plazas");

        // B. Creamos el registro de inscripción
        $stmt1 = $mysqli->prepare("INSERT INTO user_events (user_id, event_id) VALUES (?, ?)");
        $stmt1->bind_param("ii", $userId, $eventId);
        $stmt1->execute();

        // Si todo salió bien, guardamos los cambios definitivamente.
        $mysqli->commit();
        echo json_encode(["message" => "Inscrito con éxito"]);
    } catch (Exception $e) {
        // Si algo falló, deshacemos todo como si nada hubiera pasado.
        $mysqli->rollback();
        http_response_code(400); echo json_encode(["error" => $e->getMessage()]);
    }
}

// --- DESAPUNTARSE DE UN EVENTO ---
function desapuntarseEvento($mysqli, $eventId, $userId) {
    // Similar a inscribirse, pero al revés. Usamos transacción por seguridad.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli->begin_transaction();
    try {
        // 1. Borramos la inscripción
        $stmt1 = $mysqli->prepare("DELETE FROM user_events WHERE user_id = ? AND event_id = ?");
        $stmt1->bind_param("ii", $userId, $eventId);
        $stmt1->execute();

        // 2. Si se borró algo, devolvemos la plaza al evento (+1)
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

// --- VER MIS EVENTOS ---
function obtenerMisEventos($mysqli, $userId) {
    // Usamos un JOIN para combinar la tabla de eventos con la de inscripciones.
    // "Dame todos los datos del evento DONDE haya una inscripción de este usuario".
    $sql = "SELECT e.* FROM events e JOIN user_events ue ON e.id = ue.event_id WHERE ue.user_id = ? ORDER BY e.fecha ASC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
}

// --- CREAR NUEVO EVENTO (Con Subida de Imagen) ---
function crearEvento($mysqli, $input) {
    // Valor por defecto si no suben imagen
    $nombreImagen = 'default.png'; 

    // 1. GESTIÓN DE ARCHIVOS (IMAGEN)
    // Verificamos si existe $_FILES['imagen'] y si no hubo errores de subida.
    if (isset($_FILES['imagen'])) {
        $file = $_FILES['imagen'];
        
        // Comprobamos errores técnicos (tamaño excedido en php.ini, corte de conexión...)
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400); 
            echo json_encode(["error" => "Error de subida PHP Código: " . $file['error']]); 
            return;
        }

        $dir = __DIR__ . '/img'; 
        
        // Verificamos permisos de la carpeta.
        if (!is_writable($dir)) {
            http_response_code(500);
            echo json_encode(["error" => "La carpeta 'img' no tiene permisos de escritura (777)"]);
            return;
        }

        // SEGURIDAD: Validamos que sea una imagen real (MIME Type) y no un virus disfrazado de .jpg
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        $permitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if (!in_array($mime, $permitidos)) {
            http_response_code(400);
            echo json_encode(["error" => "Archivo no permitido: $mime"]);
            return;
        }

        // Renombramos el archivo con un código aleatorio para evitar colisiones 
        // (ej: si dos personas suben "foto.jpg", la segunda machacaría a la primera).
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $nuevoNombre = bin2hex(random_bytes(8)) . "." . $ext;
        $destino = $dir . '/' . $nuevoNombre;

        // Movemos el archivo de la carpeta temporal del sistema a nuestra carpeta 'img'.
        if (move_uploaded_file($file['tmp_name'], $destino)) {
            $nombreImagen = $nuevoNombre;
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Falló move_uploaded_file. ¿Ruta correcta?"]);
            return;
        }
    }

    // 2. INSERTAR DATOS EN SQL
    // Recogemos los datos del formulario. Usamos $_POST porque al venir con archivo
    // los datos no vienen en el JSON body, sino en el POST estándar.
    $titulo = $_POST['titulo'] ?? $input['titulo'];
    $tipo = $_POST['tipo'] ?? $input['tipo'];
    $fecha = $_POST['fecha'] ?? $input['fecha'];
    $hora = $_POST['hora'] ?? $input['hora'];
    $plazas = $_POST['plazasLibres'] ?? $input['plazasLibres'];
    $desc = $_POST['descripcion'] ?? $input['descripcion'];
    $creador = $_SESSION['user_id'];

    $stmt = $mysqli->prepare("INSERT INTO events (titulo, tipo, fecha, hora, plazasLibres, imagen, descripcion, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    // 'ssssissi' significa: string, string, string, string, integer, string, string, integer
    $stmt->bind_param("ssssissi", $titulo, $tipo, $fecha, $hora, $plazas, $nombreImagen, $desc, $creador);
    
    if ($stmt->execute()) echo json_encode(["message" => "Creado", "imagen" => $nombreImagen]);
    else { http_response_code(500); echo json_encode(["error" => "Error SQL"]); }
}
?>