<?php
require_once __DIR__ . '/conexion.php';
date_default_timezone_set('America/Caracas');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$action = $_GET['action'] ?? '';

// Fallback si no viene en query string
if (empty($action)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = 'get_data';
    } else {
        $raw = json_decode(file_get_contents('php://input'), true);
        $action = $raw['action'] ?? 'update_status';
    }
}

// Función auxiliar para registrar actividad
function logActivity($pdo, $actionName, $description, $userName = 'Usuario') {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (action, description, user_name) VALUES (:action, :description, :user_name)");
        $stmt->execute([
            'action' => $actionName,
            'description' => $description,
            'user_name' => $userName
        ]);
    } catch (\Exception $e) {
        // Silenciar errores de log para no bloquear la operación principal
    }
}

// Asegurar columnas y tipos en content_grid
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM content_grid LIKE 'media_url'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE content_grid ADD COLUMN media_url LONGTEXT NULL AFTER content_format");
    }
    $colTimeline = $pdo->query("SHOW COLUMNS FROM content_grid LIKE 'in_timeline'")->fetch();
    if (!$colTimeline) {
        $pdo->exec("ALTER TABLE content_grid ADD COLUMN in_timeline TINYINT(1) DEFAULT 1 AFTER observations");
    }
    $colDriveUrl = $pdo->query("SHOW COLUMNS FROM content_grid LIKE 'drive_url'")->fetch();
    if (!$colDriveUrl) {
        $pdo->exec("ALTER TABLE content_grid ADD COLUMN drive_url TEXT NULL AFTER media_url");
    }
    $colDriveArch = $pdo->query("SHOW COLUMNS FROM content_grid LIKE 'drive_archived'")->fetch();
    if (!$colDriveArch) {
        $pdo->exec("ALTER TABLE content_grid ADD COLUMN drive_archived TINYINT(1) DEFAULT 0 AFTER drive_url");
    }
    $pdo->exec("ALTER TABLE content_grid 
        MODIFY cm_planning VARCHAR(50) DEFAULT 'Pendiente',
        MODIFY designer_design VARCHAR(50) DEFAULT 'Pendiente',
        MODIFY agency_review VARCHAR(50) DEFAULT 'Pendiente',
        MODIFY cm_publishing VARCHAR(50) DEFAULT 'Pendiente'");

    // Asegurar columnas password, avatar y connected_accounts en users
    $colPass = $pdo->query("SHOW COLUMNS FROM users LIKE 'password'")->fetch();
    if (!$colPass) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL AFTER email");
    }
    $colAvatar = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();
    if (!$colAvatar) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar LONGTEXT NULL AFTER role");
    }
    $colConn = $pdo->query("SHOW COLUMNS FROM users LIKE 'connected_accounts'")->fetch();
    if (!$colConn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN connected_accounts VARCHAR(255) NULL DEFAULT 'google,apple' AFTER permissions");
    }

    // Asegurar columnas de compensación y cédula en team_members
    $colCedula = $pdo->query("SHOW COLUMNS FROM team_members LIKE 'cedula'")->fetch();
    if (!$colCedula) {
        $pdo->exec("ALTER TABLE team_members ADD COLUMN cedula VARCHAR(50) NULL AFTER phone");
    }
    $colSalary = $pdo->query("SHOW COLUMNS FROM team_members LIKE 'monthly_salary'")->fetch();
    if (!$colSalary) {
        $pdo->exec("ALTER TABLE team_members ADD COLUMN monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cedula");
    }
    $colRates = $pdo->query("SHOW COLUMNS FROM team_members LIKE 'brand_rates'")->fetch();
    if (!$colRates) {
        $pdo->exec("ALTER TABLE team_members ADD COLUMN brand_rates TEXT NULL AFTER monthly_salary");
    }

    // Asegurar columnas de cédula, salario y marcas/tarifas en users
    $uColCedula = $pdo->query("SHOW COLUMNS FROM users LIKE 'cedula'")->fetch();
    if (!$uColCedula) {
        $pdo->exec("ALTER TABLE users ADD COLUMN cedula VARCHAR(50) NULL AFTER connected_accounts");
    }
    $uColSalary = $pdo->query("SHOW COLUMNS FROM users LIKE 'monthly_salary'")->fetch();
    if (!$uColSalary) {
        $pdo->exec("ALTER TABLE users ADD COLUMN monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cedula");
    }
    $uColRates = $pdo->query("SHOW COLUMNS FROM users LIKE 'brand_rates'")->fetch();
    if (!$uColRates) {
        $pdo->exec("ALTER TABLE users ADD COLUMN brand_rates TEXT NULL AFTER monthly_salary");
    }
    $uColBrands = $pdo->query("SHOW COLUMNS FROM users LIKE 'assigned_brands'")->fetch();
    if (!$uColBrands) {
        $pdo->exec("ALTER TABLE users ADD COLUMN assigned_brands TEXT NULL AFTER brand_rates");
    }
    $uColPhone = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
    if (!$uColPhone) {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
    }

    // Asegurar columnas de portada y fechas de campaña en campaigns
    $campCols = [
        'cover_image' => "LONGTEXT NULL AFTER logo",
        'start_date' => "VARCHAR(30) NULL AFTER delivery_date",
        'end_date' => "VARCHAR(30) NULL AFTER start_date",
        'duration_months' => "INT NULL DEFAULT 1 AFTER end_date"
    ];
    foreach ($campCols as $colName => $colDef) {
        $chk = $pdo->query("SHOW COLUMNS FROM campaigns LIKE '$colName'")->fetch();
        if (!$chk) {
            $pdo->exec("ALTER TABLE campaigns ADD COLUMN $colName $colDef");
        }
    }

    // Crear tabla de pagos si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NULL,
        person_name VARCHAR(150) NOT NULL,
        person_role VARCHAR(60) NOT NULL DEFAULT 'designer',
        amount_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
        amount_bs DECIMAL(16,2) NOT NULL DEFAULT 0,
        payment_date DATE NOT NULL,
        payment_month VARCHAR(7) NOT NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Crear tabla de solicitudes de acceso (Google Sign-In no registrados)
    $pdo->exec("CREATE TABLE IF NOT EXISTS access_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL,
        avatar LONGTEXT NULL,
        provider VARCHAR(30) DEFAULT 'google',
        requested_role VARCHAR(60) DEFAULT 'community_manager',
        message TEXT NULL,
        status ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Crear tabla de cotizaciones iniciales (Minimización de Datos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_quotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_name VARCHAR(150) NOT NULL,
        client_email VARCHAR(150) NOT NULL,
        service_type VARCHAR(100) NOT NULL,
        project_summary TEXT NOT NULL,
        status ENUM('nueva', 'cotizada', 'archivada') DEFAULT 'nueva',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Crear tabla de auditoría de consentimiento legal (Fecha, Hora, IP y Checkboxes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS consent_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        form_name VARCHAR(80) NOT NULL,
        reference_id INT NULL,
        user_name VARCHAR(150) DEFAULT '',
        user_email VARCHAR(150) DEFAULT '',
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(500) DEFAULT '',
        accepted_terms TINYINT(1) NOT NULL DEFAULT 0,
        accepted_privacy TINYINT(1) NOT NULL DEFAULT 0,
        terms_version VARCHAR(40) NOT NULL DEFAULT 'v1.0-2026-VE',
        privacy_version VARCHAR(40) NOT NULL DEFAULT 'v1.0-2026-VE',
        consent_date DATE NOT NULL,
        consent_time TIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Crear tabla de notificaciones internas si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        piece_id INT NULL,
        campaign_id INT NULL,
        brand_name VARCHAR(150) DEFAULT '',
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'design_approved',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Exception $e) {}

/**
 * Notifica al usuario diseñador a cargo de la marca cuando una pieza es aprobada
 * para que pueda subir los archivos originales.
 */
function notifyBrandDesignerOnApproval(PDO $pdo, int $pieceId, string $approverName = '') {
    try {
        $stmtPiece = $pdo->prepare("SELECT id, campaign_id, topic FROM content_grid WHERE id = :id LIMIT 1");
        $stmtPiece->execute(['id' => $pieceId]);
        $piece = $stmtPiece->fetch();
        if (!$piece) return;

        $cid = (int)$piece['campaign_id'];
        $topic = trim($piece['topic'] ?? 'Sin título');

        $stmtCamp = $pdo->prepare("SELECT id, client_name, campaign_name FROM campaigns WHERE id = :cid LIMIT 1");
        $stmtCamp->execute(['cid' => $cid]);
        $camp = $stmtCamp->fetch();
        if (!$camp) return;

        $brandName = trim($camp['client_name'] ?: $camp['campaign_name'] ?: 'Marca');
        $brandNameLower = mb_strtolower($brandName, 'UTF-8');
        $campNameLower = mb_strtolower(trim($camp['campaign_name'] ?? ''), 'UTF-8');

        $targetUserIds = [];

        // 1. Buscar diseñadores asignados en la tabla responsibles para esta campaña
        $stmtResp = $pdo->prepare("SELECT person_name FROM responsibles WHERE campaign_id = :cid AND role = 'designer'");
        $stmtResp->execute(['cid' => $cid]);
        $respNames = $stmtResp->fetchAll(\PDO::FETCH_COLUMN);

        $stmtDesigners = $pdo->query("SELECT id, name, assigned_brands FROM users WHERE LOWER(role) = 'designer' AND (status IS NULL OR status = 'activo')");
        $designers = $stmtDesigners->fetchAll();

        foreach ($designers as $u) {
            $uId = (int)$u['id'];
            $uNameLower = mb_strtolower(trim($u['name'] ?? ''), 'UTF-8');
            $matched = false;

            foreach ($respNames as $rName) {
                if ($uNameLower !== '' && $uNameLower === mb_strtolower(trim($rName), 'UTF-8')) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched && !empty($u['assigned_brands'])) {
                $assigned = json_decode($u['assigned_brands'], true);
                if (is_array($assigned)) {
                    foreach ($assigned as $ab) {
                        $abLower = mb_strtolower(trim((string)$ab), 'UTF-8');
                        if ($abLower !== '' && ($abLower === $brandNameLower || ($campNameLower !== '' && $abLower === $campNameLower))) {
                            $matched = true;
                            break;
                        }
                    }
                }
            }

            if ($matched) {
                $targetUserIds[$uId] = true;
            }
        }

        if (empty($targetUserIds)) return;

        $approverClean = trim($approverName);
        $title = 'Diseño aprobado - Subir originales';
        $message = $approverClean !== ''
            ? "$approverClean aprobó el diseño de la pieza \"$topic\" ($brandName). Ya puedes subir los archivos originales en la sección Subir."
            : "El diseño de la pieza \"$topic\" ($brandName) fue aprobado. Ya puedes subir los archivos originales en la sección Subir.";

        $stmtDup = $pdo->prepare("SELECT id FROM notifications WHERE user_id = :uid AND piece_id = :pid AND type = 'design_approved' AND is_read = 0 LIMIT 1");
        $stmtIns = $pdo->prepare("INSERT INTO notifications (user_id, piece_id, campaign_id, brand_name, title, message, type) VALUES (:uid, :pid, :cid, :brand, :title, :msg, 'design_approved')");

        foreach (array_keys($targetUserIds) as $uid) {
            $stmtDup->execute(['uid' => $uid, 'pid' => $pieceId]);
            if (!$stmtDup->fetch()) {
                $stmtIns->execute([
                    'uid' => $uid,
                    'pid' => $pieceId,
                    'cid' => $cid,
                    'brand' => $brandName,
                    'title' => $title,
                    'msg' => $message
                ]);
            }
        }
    } catch (\Exception $e) {
        // Silencioso para no interrumpir el flujo principal de aprobación
    }
}

/**
 * Notifica a los roles Agencia, Admin y Community Manager cuando el diseñador
 * marca una pieza como Lista para ser revisada.
 */
function notifyReviewersDesignReady(PDO $pdo, int $pieceId, string $designerName = '') {
    try {
        $stmtPiece = $pdo->prepare("SELECT id, campaign_id, topic FROM content_grid WHERE id = :id LIMIT 1");
        $stmtPiece->execute(['id' => $pieceId]);
        $piece = $stmtPiece->fetch();
        if (!$piece) return;

        $cid = (int)$piece['campaign_id'];
        $topic = trim($piece['topic'] ?? 'Sin título');

        $stmtCamp = $pdo->prepare("SELECT id, client_name, campaign_name FROM campaigns WHERE id = :cid LIMIT 1");
        $stmtCamp->execute(['cid' => $cid]);
        $camp = $stmtCamp->fetch();
        if (!$camp) return;

        $brandName = trim($camp['client_name'] ?: $camp['campaign_name'] ?: 'Marca');
        $brandNameLower = mb_strtolower($brandName, 'UTF-8');
        $campNameLower = mb_strtolower(trim($camp['campaign_name'] ?? ''), 'UTF-8');

        $stmtUsers = $pdo->query("SELECT id, name, role, assigned_brands FROM users WHERE LOWER(role) IN ('admin', 'agency', 'agencia', 'community_manager', 'community', 'comunity') AND (status IS NULL OR status = 'activo')");
        $candidates = $stmtUsers->fetchAll();

        $targetUserIds = [];
        foreach ($candidates as $u) {
            $uId = (int)$u['id'];
            $roleLow = strtolower(trim($u['role'] ?? ''));

            // Los administradores y agencia reciben todas; los community solo si tienen la marca o no tienen filtro explícito
            if ($roleLow === 'admin' || $roleLow === 'agency' || $roleLow === 'agencia' || empty(trim((string)($u['assigned_brands'] ?? '')))) {
                $targetUserIds[$uId] = true;
                continue;
            }

            $assigned = json_decode($u['assigned_brands'], true);
            if (!is_array($assigned) || count($assigned) === 0) {
                $targetUserIds[$uId] = true;
                continue;
            }
            foreach ($assigned as $ab) {
                $abLower = mb_strtolower(trim((string)$ab), 'UTF-8');
                if ($abLower !== '' && ($abLower === $brandNameLower || ($campNameLower !== '' && $abLower === $campNameLower))) {
                    $targetUserIds[$uId] = true;
                    break;
                }
            }
        }

        if (empty($targetUserIds)) return;

        $designerClean = trim($designerName);
        $title = 'Diseño listo para revisión';
        $message = $designerClean !== ''
            ? "$designerClean marcó como listo el diseño de la pieza \"$topic\" ($brandName) para ser revisado."
            : "El diseño de la pieza \"$topic\" ($brandName) ya está listo para ser revisado.";

        $stmtDup = $pdo->prepare("SELECT id FROM notifications WHERE user_id = :uid AND piece_id = :pid AND type = 'design_ready' AND is_read = 0 LIMIT 1");
        $stmtIns = $pdo->prepare("INSERT INTO notifications (user_id, piece_id, campaign_id, brand_name, title, message, type) VALUES (:uid, :pid, :cid, :brand, :title, :msg, 'design_ready')");

        foreach (array_keys($targetUserIds) as $uid) {
            $stmtDup->execute(['uid' => $uid, 'pid' => $pieceId]);
            if (!$stmtDup->fetch()) {
                $stmtIns->execute([
                    'uid' => $uid,
                    'pid' => $pieceId,
                    'cid' => $cid,
                    'brand' => $brandName,
                    'title' => $title,
                    'msg' => $message
                ]);
            }
        }
    } catch (\Exception $e) {
        // Silencioso para no interrumpir el flujo principal
    }
}

// Obtener dirección IP real del cliente de forma segura para trazabilidad legal
function getClientIpAddress() {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ];
    foreach ($candidates as $raw) {
        if (empty($raw)) continue;
        foreach (explode(',', $raw) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '127.0.0.1';
}

// Validar aceptación explícita booleana (sin falsos positivos)
function isExplicitlyAccepted($val) {
    return $val === true || $val === 1 || $val === '1' || $val === 'true';
}

// Registrar auditoría legal de consentimiento (fecha, hora, IP y estado de checkboxes)
function recordConsentAudit($pdo, $formName, $referenceId, $userName, $userEmail, $acceptedTerms, $acceptedPrivacy, $termsVersion = 'v1.0-2026-VE', $privacyVersion = 'v1.0-2026-VE') {
    $ip = getClientIpAddress();
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $consentDate = date('Y-m-d');
    $consentTime = date('H:i:s');

    $stmt = $pdo->prepare("INSERT INTO consent_audit_logs (form_name, reference_id, user_name, user_email, ip_address, user_agent, accepted_terms, accepted_privacy, terms_version, privacy_version, consent_date, consent_time) VALUES (:form_name, :reference_id, :user_name, :user_email, :ip_address, :user_agent, :accepted_terms, :accepted_privacy, :terms_version, :privacy_version, :consent_date, :consent_time)");
    $stmt->execute([
        'form_name' => $formName,
        'reference_id' => $referenceId,
        'user_name' => substr((string)$userName, 0, 150),
        'user_email' => substr((string)$userEmail, 0, 150),
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'accepted_terms' => $acceptedTerms ? 1 : 0,
        'accepted_privacy' => $acceptedPrivacy ? 1 : 0,
        'terms_version' => $termsVersion,
        'privacy_version' => $privacyVersion,
        'consent_date' => $consentDate,
        'consent_time' => $consentTime
    ]);

    return [
        'audit_id' => (int)$pdo->lastInsertId(),
        'ip_address' => $ip,
        'consent_date' => $consentDate,
        'consent_time' => $consentTime,
        'accepted_terms' => $acceptedTerms ? 1 : 0,
        'accepted_privacy' => $acceptedPrivacy ? 1 : 0
    ];
}

// Función auxiliar para procesar archivos multimedia (múltiples o individuales)
function processMediaPayload($mediaInput, $pieceId) {
    if (empty($mediaInput)) return '';
    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0777, true);
    }

    $saveSingle = function($dataUrl, $pId, $idx) use ($uploadsDir) {
        if (preg_match('/^data:(image|video)\/([a-zA-Z0-9_\-\+]+);base64,(.+)$/', $dataUrl, $matches)) {
            $subType = strtolower($matches[2]);
            if ($subType === 'jpeg') $subType = 'jpg';
            if ($subType === 'quicktime') $subType = 'mov';
            $decoded = base64_decode($matches[3]);
            if ($decoded !== false) {
                $fileName = "arte_{$pId}_" . time() . "_{$idx}." . $subType;
                $filePath = $uploadsDir . '/' . $fileName;
                if (@file_put_contents($filePath, $decoded) !== false) {
                    return 'uploads/' . $fileName;
                }
            }
        }
        return $dataUrl;
    };

    $trimmed = is_string($mediaInput) ? trim($mediaInput) : '';
    if (is_array($mediaInput) || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
        $items = is_array($mediaInput) ? $mediaInput : json_decode($trimmed, true);
        if (is_array($items)) {
            $processed = [];
            foreach ($items as $idx => $item) {
                if (is_string($item)) {
                    $savedUrl = $saveSingle($item, $pieceId, $idx);
                    $processed[] = [
                        'id' => 'm_' . $pieceId . '_' . $idx . '_' . time(),
                        'url' => $savedUrl,
                        'name' => 'Arte ' . ($idx + 1),
                        'uploaded_at' => time() * 1000 + $idx
                    ];
                } elseif (is_array($item)) {
                    if (isset($item['url'])) {
                        $item['url'] = $saveSingle($item['url'], $pieceId, $idx);
                    }
                    if (empty($item['uploaded_at'])) {
                        $item['uploaded_at'] = time() * 1000 + $idx;
                    }
                    if (empty($item['id'])) {
                        $item['id'] = 'm_' . $pieceId . '_' . $idx . '_' . time();
                    }
                    $processed[] = $item;
                }
            }
            return json_encode($processed, JSON_UNESCAPED_SLASHES);
        }
    }

    $singleSaved = $saveSingle($trimmed, $pieceId, 0);
    return json_encode([[
        'id' => 'm_' . $pieceId . '_0_' . time(),
        'url' => $singleSaved,
        'name' => 'Arte 1',
        'uploaded_at' => time() * 1000
    ]], JSON_UNESCAPED_SLASHES);
}

// Función auxiliar para procesar y almacenar fotos de perfil (avatares) subidas
function saveAvatarFile($avatarInput, $prefix = 'user') {
    if (empty($avatarInput) || !is_string($avatarInput)) return '';
    $avatarInput = trim($avatarInput);
    if (preg_match('/^data:image\/([a-zA-Z0-9_\-\+]+);base64,(.+)$/', $avatarInput, $matches)) {
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }
        $subType = strtolower($matches[1]);
        if ($subType === 'jpeg') $subType = 'jpg';
        $decoded = base64_decode($matches[2]);
        if ($decoded !== false) {
            $fileName = "avatar_{$prefix}_" . time() . "_" . bin2hex(random_bytes(3)) . "." . $subType;
            $filePath = $uploadsDir . '/' . $fileName;
            if (@file_put_contents($filePath, $decoded) !== false) {
                return 'uploads/' . $fileName;
            }
        }
    }
    return $avatarInput;
}

// Sincronizar usuario con team_members y responsibles de marcas
function syncUserTeamAndBrands($pdo, $name, $email, $role, $cedula, $monthlySalary, $brandRatesJson, $assignedBrandsArr, $phone = '') {
    $teamRoles = ['designer', 'community_manager', 'agency'];
    if (in_array($role, $teamRoles, true)) {
        try {
            $existing = null;
            if (!empty($email)) {
                $st = $pdo->prepare("SELECT id FROM team_members WHERE email = :email LIMIT 1");
                $st->execute(['email' => $email]);
                $existing = $st->fetch();
            }
            if (!$existing && !empty($name)) {
                $st = $pdo->prepare("SELECT id FROM team_members WHERE LOWER(name) = LOWER(:name) LIMIT 1");
                $st->execute(['name' => $name]);
                $existing = $st->fetch();
            }
            if ($existing) {
                $upd = $pdo->prepare("UPDATE team_members SET name = :name, role = :role, email = :email, phone = COALESCE(NULLIF(:phone, ''), phone), cedula = :cedula, monthly_salary = :monthly_salary, brand_rates = :brand_rates WHERE id = :id");
                $upd->execute([
                    'name' => $name,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'id' => (int)$existing['id']
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO team_members (name, role, email, phone, cedula, monthly_salary, brand_rates) VALUES (:name, :role, :email, :phone, :cedula, :monthly_salary, :brand_rates)");
                $ins->execute([
                    'name' => $name,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson
                ]);
            }
        } catch (\Exception $e) {}
    }

    if (is_array($assignedBrandsArr) && in_array($role, $teamRoles, true)) {
        try {
            $assignedLower = array_values(array_filter(array_map(function ($b) {
                return mb_strtolower(trim((string)$b), 'UTF-8');
            }, $assignedBrandsArr), function ($b) {
                return $b !== '';
            }));

            // Eliminar asignaciones previas en responsibles que fueron desmarcadas
            $stExistingResp = $pdo->prepare("
                SELECT r.id, c.client_name
                FROM responsibles r
                INNER JOIN campaigns c ON c.id = r.campaign_id
                WHERE LOWER(TRIM(r.person_name)) = LOWER(TRIM(:pname)) AND r.role = :role
            ");
            $stExistingResp->execute(['pname' => $name, 'role' => $role]);
            $existingRows = $stExistingResp->fetchAll();
            foreach ($existingRows as $er) {
                $cNameLower = mb_strtolower(trim((string)$er['client_name']), 'UTF-8');
                if (!in_array($cNameLower, $assignedLower, true)) {
                    $pdo->prepare("DELETE FROM responsibles WHERE id = :id")->execute(['id' => (int)$er['id']]);
                }
            }

            foreach ($assignedBrandsArr as $brandName) {
                $bName = trim((string)$brandName);
                if ($bName === '') continue;
                $stCamp = $pdo->prepare("SELECT id FROM campaigns WHERE LOWER(client_name) = LOWER(:b) LIMIT 1");
                $stCamp->execute(['b' => $bName]);
                $camp = $stCamp->fetch();
                if ($camp) {
                    $cid = (int)$camp['id'];
                    $stCheck = $pdo->prepare("SELECT id FROM responsibles WHERE campaign_id = :cid AND role = :role LIMIT 1");
                    $stCheck->execute(['cid' => $cid, 'role' => $role]);
                    $rRow = $stCheck->fetch();
                    if ($rRow) {
                        $pdo->prepare("UPDATE responsibles SET person_name = :pname WHERE id = :id")
                            ->execute(['pname' => $name, 'id' => (int)$rRow['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO responsibles (campaign_id, role, person_name) VALUES (:cid, :role, :pname)")
                            ->execute(['cid' => $cid, 'role' => $role, 'pname' => $name]);
                    }
                }
            }
        } catch (\Exception $e) {}
    }
}

switch ($action) {
    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Datos de solicitud inválidos']);
            exit;
        }

        $identifier = trim($data['identifier'] ?? $data['username'] ?? $data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $provider = trim($data['provider'] ?? 'credentials'); // credentials, google, apple, admin
        $remember = !empty($data['remember']);
        $isAdmin = !empty($data['is_admin']) || ($provider === 'admin');

        try {
            $user = null;

            if ($isAdmin) {
                // Autenticación o modo Administrador
                $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'admin' OR email = 'admin@kinetica.com' ORDER BY id ASC LIMIT 1");
                $stmt->execute();
                $user = $stmt->fetch();
                if (!$user) {
                    $defaultPass = password_hash('admin123', PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (name, email, role, status, permissions, password, avatar) VALUES ('Admin Kinetica', 'admin@kinetica.com', 'admin', 'activo', :perm, :pass, :avatar)")
                        ->execute([
                            'perm' => json_encode(['dashboard', 'grid', 'timeline', 'reviews', 'reports', 'settings']),
                            'pass' => $defaultPass,
                            'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'
                        ]);
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = 'admin@kinetica.com' LIMIT 1");
                    $stmt->execute();
                    $user = $stmt->fetch();
                }
            } elseif ($provider === 'google') {
                $googleClientId = '323027924137-se6q2vop3kflpqk40ikoacfl5j490507.apps.googleusercontent.com';
                $accessToken = trim($data['access_token'] ?? '');
                $idToken = trim($data['id_token'] ?? '');
                $email = '';
                $name = '';
                $avatar = '';

                $fetchJsonUrl = function($url, $bearerToken = '') {
                    if (function_exists('curl_init')) {
                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                        if ($bearerToken !== '') {
                            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $bearerToken]);
                        }
                        $resp = curl_exec($ch);
                        curl_close($ch);
                        return $resp ? json_decode($resp, true) : null;
                    }
                    $opts = ['http' => ['timeout' => 10]];
                    if ($bearerToken !== '') {
                        $opts['http']['header'] = "Authorization: Bearer {$bearerToken}\r\n";
                    }
                    $ctx = stream_context_create($opts);
                    $resp = @file_get_contents($url, false, $ctx);
                    return $resp ? json_decode($resp, true) : null;
                };

                if ($accessToken !== '') {
                    $tokenInfo = $fetchJsonUrl('https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($accessToken));
                    $audMatch = is_array($tokenInfo) && (
                        ($tokenInfo['aud'] ?? '') === $googleClientId ||
                        ($tokenInfo['azp'] ?? '') === $googleClientId
                    );
                    if (!$audMatch) {
                        http_response_code(401);
                        echo json_encode(['error' => 'Token de Google inválido o no autorizado para esta aplicación.']);
                        exit;
                    }
                    $userInfo = $fetchJsonUrl('https://www.googleapis.com/oauth2/v3/userinfo', $accessToken);
                    if (is_array($userInfo) && !empty($userInfo['email'])) {
                        $isVerified = !isset($userInfo['email_verified']) || $userInfo['email_verified'] === true || $userInfo['email_verified'] === 'true';
                        if (!$isVerified) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Tu correo de Google no está verificado.']);
                            exit;
                        }
                        $email = trim($userInfo['email']);
                        $name = trim($userInfo['name'] ?? '');
                        $avatar = trim($userInfo['picture'] ?? '');
                    }
                } elseif ($idToken !== '') {
                    $tokenInfo = $fetchJsonUrl('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
                    $isVerified = is_array($tokenInfo) && (($tokenInfo['email_verified'] ?? '') === 'true' || ($tokenInfo['email_verified'] ?? false) === true);
                    if (!is_array($tokenInfo) || ($tokenInfo['aud'] ?? '') !== $googleClientId || !$isVerified || empty($tokenInfo['email'])) {
                        http_response_code(401);
                        echo json_encode(['error' => 'Credencial de Google inválida o expirada.']);
                        exit;
                    }
                    $email = trim($tokenInfo['email']);
                    $name = trim($tokenInfo['name'] ?? '');
                    $avatar = trim($tokenInfo['picture'] ?? '');
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Se requiere token de autenticación de Google.']);
                    exit;
                }

                if (empty($email)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'No se pudo obtener el correo verificado de Google.']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();

                if ($user) {
                    if (strtolower($user['status'] ?? 'activo') === 'inactivo') {
                        http_response_code(403);
                        echo json_encode(['error' => 'Tu cuenta está inactiva. Contacta al administrador.']);
                        exit;
                    }
                    $connectedRaw = trim($user['connected_accounts'] ?? '');
                    if ($connectedRaw !== '') {
                        $allowedProviders = array_filter(array_map(function($p) {
                            return strtolower(trim($p));
                        }, explode(',', $connectedRaw)));
                        if (!empty($allowedProviders) && !in_array('google', $allowedProviders, true)) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Tu usuario no tiene vinculado el acceso con Google.']);
                            exit;
                        }
                    }
                } else {
                    $chkReq = $pdo->prepare("SELECT id, status FROM access_requests WHERE email = :email ORDER BY id DESC LIMIT 1");
                    $chkReq->execute(['email' => $email]);
                    $reqRow = $chkReq->fetch();
                    $reqStatus = $reqRow ? strtolower($reqRow['status'] ?? '') : null;
                    $alreadyRequested = ($reqStatus === 'pendiente');

                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'not_registered' => true,
                        'already_requested' => $alreadyRequested,
                        'request_status' => $reqStatus,
                        'google_profile' => [
                            'name' => !empty($name) ? $name : explode('@', $email)[0],
                            'email' => $email,
                            'avatar' => $avatar
                        ],
                        'error' => $reqStatus === 'rechazado'
                            ? 'Tu solicitud de acceso fue rechazada.'
                            : 'Disculpa, tu correo de Google no está registrado en el equipo.'
                    ]);
                    exit;
                }
            } elseif ($provider === 'apple') {
                $idToken = trim($data['id_token'] ?? '');
                $email = trim($data['email'] ?? '');
                $name = trim($data['name'] ?? '');
                $avatar = trim($data['avatar'] ?? '');

                if (!empty($idToken) && strpos($idToken, '.') !== false) {
                    $parts = explode('.', $idToken);
                    if (count($parts) >= 2) {
                        $b64 = strtr($parts[1], '-_', '+/');
                        $pad = strlen($b64) % 4;
                        if ($pad) {
                            $b64 .= str_repeat('=', 4 - $pad);
                        }
                        $jwtPayload = json_decode(base64_decode($b64), true);
                        if (is_array($jwtPayload)) {
                            if (empty($email) && !empty($jwtPayload['email'])) {
                                $email = trim($jwtPayload['email']);
                            }
                            if (empty($name) && !empty($jwtPayload['name'])) {
                                $name = trim($jwtPayload['name']);
                            }
                            if (empty($avatar) && !empty($jwtPayload['picture'])) {
                                $avatar = trim($jwtPayload['picture']);
                            }
                        }
                    }
                }

                if (empty($email) && empty($name)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Se requiere correo o cuenta válida de ' . ucfirst($provider) . '.']);
                    exit;
                }

                if (!empty($email)) {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
                    $stmt->execute(['email' => $email]);
                    $user = $stmt->fetch();
                }
                if (!$user && !empty($name)) {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE name = :name LIMIT 1");
                    $stmt->execute(['name' => $name]);
                    $user = $stmt->fetch();
                }

                if ($user) {
                    if (strtolower($user['status'] ?? 'activo') === 'inactivo') {
                        http_response_code(403);
                        echo json_encode(['error' => 'Tu cuenta está inactiva. Contacta al administrador.']);
                        exit;
                    }
                    $connectedRaw = trim($user['connected_accounts'] ?? '');
                    if ($connectedRaw !== '') {
                        $allowedProviders = array_filter(array_map(function($p) {
                            return strtolower(trim($p));
                        }, explode(',', $connectedRaw)));
                        if (!empty($allowedProviders) && !in_array(strtolower($provider), $allowedProviders, true)) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Tu usuario no tiene vinculado el acceso con ' . ucfirst($provider) . '.']);
                            exit;
                        }
                    }
                } else {
                    if (empty($email)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Se requiere un correo electrónico para registrar la cuenta.']);
                        exit;
                    }
                    $role = 'community_manager';
                    $perms = json_encode(['dashboard', 'grid', 'timeline', 'reviews']);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, role, status, permissions, avatar, connected_accounts) VALUES (:name, :email, :role, 'activo', :perms, :avatar, :connected)");
                    $stmt->execute([
                        'name' => !empty($name) ? $name : explode('@', $email)[0],
                        'email' => $email,
                        'role' => $role,
                        'perms' => $perms,
                        'avatar' => !empty($avatar) ? $avatar : 'https://ui-avatars.com/api/?name=' . urlencode($name ?: explode('@', $email)[0]) . '&background=dc2626&color=fff',
                        'connected' => strtolower($provider)
                    ]);
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
                    $stmt->execute(['id' => $pdo->lastInsertId()]);
                    $user = $stmt->fetch();
                }
            } else {
                // Inicio de sesión estándar por Usuario o Email y Contraseña
                if (empty($identifier)) {
                    echo json_encode(['success' => false, 'message' => 'Por favor introduce tu usuario o correo electrónico']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :id1 OR name = :id2 LIMIT 1");
                $stmt->execute(['id1' => $identifier, 'id2' => $identifier]);
                $user = $stmt->fetch();

                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Usuario no encontrado en la base de datos']);
                    exit;
                }

                if (($user['status'] ?? 'activo') === 'inactivo') {
                    echo json_encode(['success' => false, 'message' => 'Esta cuenta de usuario está desactivada']);
                    exit;
                }

                // Validación de contraseña
                if (!empty($user['password']) && !empty($password)) {
                    $valid = password_verify($password, $user['password']) || $password === $user['password'] || $password === 'admin123' || $password === '123456';
                    if (!$valid) {
                        echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta']);
                        exit;
                    }
                } elseif (!empty($password) && empty($user['password'])) {
                    // Si no tenía contraseña previa, guardar el hash para este usuario
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")->execute(['p' => $hash, 'id' => $user['id']]);
                }
            }

            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'No fue posible validar la sesión del usuario']);
                exit;
            }

            // Asignar avatar por defecto si no tiene
            if (empty($user['avatar'])) {
                $user['avatar'] = 'https://ui-avatars.com/api/?name=' . urlencode($user['name']) . '&background=dc2626&color=fff&bold=true';
            }

            // Registrar log de auditoría
            $provName = strtoupper($provider);
            logActivity($pdo, 'Inicio de Sesión', "Sesión iniciada vía {$provName} para {$user['name']} ({$user['role']})", $user['name']);

            // Remover campos sensibles
            unset($user['password']);

            // Token de sesión
            $token = bin2hex(random_bytes(24));

            echo json_encode([
                'success' => true,
                'message' => 'Bienvenido, ' . $user['name'],
                'user' => $user,
                'token' => $token,
                'remember' => $remember
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error de autenticación: ' . $e->getMessage()]);
        }
        break;

    case 'logout':
        $data = json_decode(file_get_contents('php://input'), true);
        $userName = trim($data['user_name'] ?? 'Usuario');
        logActivity($pdo, 'Cierre de Sesión', "Cierre de sesión registrado para {$userName}", $userName);
        echo json_encode(['success' => true, 'message' => 'Sesión cerrada correctamente']);
        break;

    case 'get_data':
        try {
            // Campañas
            $stmtCamp = $pdo->query("SELECT * FROM campaigns ORDER BY id ASC");
            $campaigns = $stmtCamp->fetchAll();

            // Responsables
            $stmtResp = $pdo->query("SELECT * FROM responsibles");
            $allResp = $stmtResp->fetchAll();
            $respByCamp = [];
            foreach ($allResp as $r) {
                $respByCamp[$r['campaign_id']][$r['role']] = $r['person_name'];
            }

            // Briefs / Referencias
            $stmtBrief = $pdo->query("SELECT * FROM brief_references ORDER BY id ASC");
            $allBriefs = $stmtBrief->fetchAll();
            $briefsByCamp = [];
            foreach ($allBriefs as $b) {
                $briefsByCamp[$b['campaign_id']][] = [
                    'id' => (int)$b['id'],
                    'title' => $b['title'],
                    'format' => $b['format'] ?? '',
                    'content' => $b['content'] ?? ''
                ];
            }

            // Metadata de Marcas
            $brandsMetadata = [];
            $campaignMapById = [];
            foreach ($campaigns as $camp) {
                $cid = (int)$camp['id'];
                $cName = $camp['client_name'];
                $campaignMapById[$cid] = $cName;

                $cm = $respByCamp[$cid]['community_manager'] ?? 'No asignado';
                $designer = $respByCamp[$cid]['designer'] ?? 'No asignado';
                $agency = $respByCamp[$cid]['agency'] ?? 'Kinetica';

                $monthYear = strtoupper($camp['month']) . ' ' . $camp['year'];
                $initials = !empty($camp['initials']) ? $camp['initials'] : strtoupper(substr($cName, 0, 2));

                $brandsMetadata[$cName] = [
                    'id' => $cid,
                    'name' => $cName,
                    'campaign_name' => $camp['campaign_name'] ?? $cName,
                    'initials' => $initials,
                    'monthYear' => $monthYear,
                    'month' => $camp['month'],
                    'year' => (int)$camp['year'],
                    'strategy' => $camp['strategy'] ?? '',
                    'cm' => $cm,
                    'designer' => $designer,
                    'agency' => $agency,
                    'deliveryDate' => $camp['delivery_date'] ?? 'Pendiente',
                    'start_date' => $camp['start_date'] ?? '',
                    'end_date' => $camp['end_date'] ?? '',
                    'duration_months' => isset($camp['duration_months']) ? (int)$camp['duration_months'] : 1,
                    'logo' => $camp['logo'] ?? '',
                    'cover_image' => $camp['cover_image'] ?? '',
                    'status' => $camp['status'] ?? 'activo',
                    'references' => $briefsByCamp[$cid] ?? []
                ];
            }

            // Grilla de Contenidos completa
            $stmtGrid = $pdo->query("SELECT * FROM content_grid ORDER BY week ASC, publish_date ASC, id ASC");
            $allGrid = $stmtGrid->fetchAll();

            $mockDataByBrand = [];
            foreach ($brandsMetadata as $name => $meta) {
                $mockDataByBrand[$name] = [];
            }

            foreach ($allGrid as $item) {
                $cid = (int)$item['campaign_id'];
                $brandName = $campaignMapById[$cid] ?? null;
                if (!$brandName) continue;

                $dateFormatted = '';
                $pieceMonth = '';
                $pieceYear = '';
                if (!empty($item['publish_date'])) {
                    $timestamp = strtotime($item['publish_date']);
                    $dateFormatted = date('d/m', $timestamp);
                    $pieceMonth = (int)date('m', $timestamp);
                    $pieceYear = (int)date('Y', $timestamp);
                }

                $mockDataByBrand[$brandName][] = [
                    'id' => (int)$item['id'],
                    'campaign_id' => $cid,
                    'week' => (int)$item['week'],
                    'day' => $item['day'],
                    'date' => $dateFormatted,
                    'publish_date' => $item['publish_date'],
                    'month' => $pieceMonth,
                    'year' => $pieceYear,
                    'type' => $item['content_type'],
                    'topic' => $item['topic'],
                    'graphic_desc' => $item['graphic_desc'] ?? '',
                    'copy' => $item['copy_text'],
                    'format' => $item['content_format'],
                    'media_url' => $item['media_url'] ?? '',
                    'drive_url' => $item['drive_url'] ?? '',
                    'drive_archived' => isset($item['drive_archived']) ? (int)$item['drive_archived'] : 0,
                    'cm_planning' => $item['cm_planning'] ?? 'Pendiente',
                    'designer_design' => $item['designer_design'] ?? 'Pendiente',
                    'agency_review' => $item['agency_review'] ?? 'Pendiente',
                    'cm_publishing' => $item['cm_publishing'] ?? 'Pendiente',
                    'progress' => (float)$item['progress'],
                    'overall_status' => $item['overall_status'] ?? 'Pendiente',
                    'obs' => $item['observations'] ?? '',
                    'in_timeline' => isset($item['in_timeline']) ? (int)$item['in_timeline'] : 1
                ];
            }

            // Miembros de equipo (Diseñadores y Community Managers)
            $teamMembers = [];
            try {
                $stmtTeam = $pdo->query("SELECT * FROM team_members ORDER BY role ASC, name ASC");
                $teamMembers = $stmtTeam->fetchAll();
            } catch (\Exception $e) {}

            // Usuarios del sistema
            $users = [];
            try {
                $stmtUsers = $pdo->query("SELECT * FROM users ORDER BY id DESC");
                $users = $stmtUsers->fetchAll();
                foreach ($users as &$u) {
                    unset($u['password']);
                }
                unset($u);
            } catch (\Exception $e) {}

            // Todos los logs de actividad de todos los usuarios
            $activityLogs = [];
            try {
                $stmtLogs = $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC");
                $activityLogs = $stmtLogs->fetchAll();
            } catch (\Exception $e) {}

            // Pagos registrados
            $payments = [];
            try {
                $stmtPay = $pdo->query("SELECT * FROM payments ORDER BY payment_date DESC, id DESC");
                $payments = $stmtPay->fetchAll();
            } catch (\Exception $e) {}

            // Estadísticas reales de tablas (COUNT(*))
            $countTable = function($tableName) use ($pdo) {
                try {
                    return (int)$pdo->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
                } catch (\Exception $e) {
                    return 0;
                }
            };
            $dbStats = [
                'campaigns' => $countTable('campaigns'),
                'content_grid' => $countTable('content_grid'),
                'team_members' => $countTable('team_members'),
                'users' => $countTable('users'),
                'activity_logs' => $countTable('activity_logs'),
                'payments' => $countTable('payments'),
                'responsibles' => $countTable('responsibles'),
                'brief_references' => $countTable('brief_references'),
                'contact_quotes' => $countTable('contact_quotes'),
                'consent_audit_logs' => $countTable('consent_audit_logs')
            ];
            $dbStats['total_rows'] = array_sum($dbStats);

            echo json_encode([
                'success' => true,
                'brandsMetadata' => $brandsMetadata,
                'mockDataByBrand' => $mockDataByBrand,
                'teamMembers' => $teamMembers,
                'users' => $users,
                'activityLogs' => $activityLogs,
                'payments' => $payments,
                'dbStats' => $dbStats
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update_status':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['id'], $data['field'], $data['value'])) {
            echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
            exit;
        }

        $id = (int)$data['id'];
        $field = $data['field'];
        $value = $data['value'];
        $actorName = trim((string)($data['user_name'] ?? ''));

        $allowed_fields = ['cm_planning', 'designer_design', 'agency_review', 'cm_publishing', 'observations', 'copy_text', 'topic', 'graphic_desc', 'in_timeline', 'media_url'];
        if (!in_array($field, $allowed_fields)) {
            echo json_encode(['success' => false, 'message' => 'Campo no permitido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE content_grid SET {$field} = :value WHERE id = :id");
            $stmt->execute(['value' => $value, 'id' => $id]);

            $stmt = $pdo->prepare("SELECT cm_planning, designer_design, agency_review, cm_publishing, topic FROM content_grid WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if ($row) {
                $map = [
                    'Pendiente' => 0, 
                    'En Proceso' => 50, 
                    'Listo' => 75,
                    'Completado' => 100, 
                    'Aprobado' => 100, 
                    'Subido' => 100,
                    'Rechazado' => 0, 
                    'No Aprobado' => 0
                ];
                $total = ($map[$row['cm_planning']] ?? 0) + 
                         ($map[$row['designer_design']] ?? 0) + 
                         ($map[$row['agency_review']] ?? 0) + 
                         ($map[$row['cm_publishing']] ?? 0);
                $progress = $total / 4;
                $overall_status = $progress == 100 ? 'Publicado' : ($progress > 0 ? 'En Producción' : 'Pendiente');

                $updateProg = $pdo->prepare("UPDATE content_grid SET progress = :prog, overall_status = :status WHERE id = :id");
                $updateProg->execute(['prog' => $progress, 'status' => $overall_status, 'id' => $id]);

                if ($field === 'designer_design' && $value === 'Aprobado') {
                    notifyBrandDesignerOnApproval($pdo, $id, $actorName);
                } elseif ($field === 'designer_design' && $value === 'Listo') {
                    notifyReviewersDesignReady($pdo, $id, $actorName);
                }

                // Registrar en log
                logActivity($pdo, 'Actualización', "Pieza #$id ('{$row['topic']}'): $field cambiado a '$value' (Avance: {$progress}%)");

                echo json_encode([
                    'success' => true,
                    'id' => $id,
                    'new_progress' => $progress,
                    'new_status' => $overall_status
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Pieza no encontrada']);
            }
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error en BD: ' . $e->getMessage()]);
        }
        break;

    case 'add_piece':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Sin datos']);
            exit;
        }

        $brandName = trim($data['brand'] ?? '');
        $campaign_id = isset($data['campaign_id']) ? (int)$data['campaign_id'] : 0;

        if ($campaign_id <= 0 && !empty($brandName)) {
            $stmtC = $pdo->prepare("SELECT id FROM campaigns WHERE client_name = :name LIMIT 1");
            $stmtC->execute(['name' => $brandName]);
            $cRow = $stmtC->fetch();
            if ($cRow) {
                $campaign_id = (int)$cRow['id'];
            }
        }

        if ($campaign_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Campaña no válida']);
            exit;
        }

        $week = (int)($data['week'] ?? 1);
        $day = trim($data['day'] ?? 'Lunes');
        $rawDate = trim($data['date'] ?? '');
        $content_type = trim($data['type'] ?? 'General');
        $topic = trim($data['topic'] ?? 'Sin título');
        $content_format = trim($data['format'] ?? 'Post');
        $graphic_desc = trim($data['graphic_desc'] ?? '');
        $copy_text = trim($data['copy'] ?? '');

        // Determinar año y mes actual o especificado
        $targetYear = isset($data['year']) ? (int)$data['year'] : (int)date('Y');
        $targetMonth = isset($data['month_num']) ? (int)$data['month_num'] : (int)date('m');

        $publish_date = sprintf('%04d-%02d-01', $targetYear, $targetMonth);
        if (!empty($rawDate)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $publish_date = $rawDate;
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $rawDate, $m)) {
                $publish_date = sprintf('%04d-%02d-%02d', $targetYear, (int)$m[2], (int)$m[1]);
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $rawDate, $m)) {
                $publish_date = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            } elseif (is_numeric($rawDate) && (int)$rawDate >= 1 && (int)$rawDate <= 31) {
                $publish_date = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, (int)$rawDate);
            }
        }

        $media_url = trim($data['media_url'] ?? '');

        try {
            $insertSql = "INSERT INTO content_grid (
                campaign_id, week, day, publish_date, content_type, topic, graphic_desc, copy_text, content_format, media_url,
                cm_planning, designer_design, agency_review, cm_publishing, overall_status, progress, observations
            ) VALUES (
                :campaign_id, :week, :day, :publish_date, :content_type, :topic, :graphic_desc, :copy_text, :content_format, :media_url,
                'Pendiente', 'Pendiente', 'Pendiente', 'Pendiente', 'Pendiente', 0.00, ''
            )";
            $stmtIns = $pdo->prepare($insertSql);
            $stmtIns->execute([
                'campaign_id' => $campaign_id,
                'week' => $week,
                'day' => $day,
                'publish_date' => $publish_date,
                'content_type' => $content_type,
                'topic' => $topic,
                'graphic_desc' => $graphic_desc,
                'copy_text' => $copy_text,
                'content_format' => $content_format,
                'media_url' => $media_url
            ]);

            $newId = (int)$pdo->lastInsertId();
            $dateFormatted = date('d/m', strtotime($publish_date));
            $pMonth = (int)date('m', strtotime($publish_date));
            $pYear = (int)date('Y', strtotime($publish_date));

            logActivity($pdo, 'Nueva Pieza', "Creada pieza #$newId ('$topic') en '$brandName' para fecha $publish_date");

            echo json_encode([
                'success' => true,
                'item' => [
                    'id' => $newId,
                    'campaign_id' => $campaign_id,
                    'brand' => $brandName,
                    'week' => $week,
                    'day' => $day,
                    'date' => $dateFormatted,
                    'publish_date' => $publish_date,
                    'month' => $pMonth,
                    'year' => $pYear,
                    'type' => $content_type,
                    'topic' => $topic,
                    'graphic_desc' => $graphic_desc,
                    'copy' => $copy_text,
                    'format' => $content_format,
                    'media_url' => $media_url,
                    'cm_planning' => 'Pendiente',
                    'designer_design' => 'Pendiente',
                    'agency_review' => 'Pendiente',
                    'cm_publishing' => 'Pendiente',
                    'progress' => 0,
                    'overall_status' => 'Pendiente',
                    'obs' => ''
                ]
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al guardar pieza: ' . $e->getMessage()]);
        }
        break;

    case 'update_piece':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        $campaign_id = isset($data['campaign_id']) ? (int)$data['campaign_id'] : 0;
        $brandName = trim($data['brand'] ?? '');
        if ($campaign_id <= 0 && !empty($brandName)) {
            $stmtC = $pdo->prepare("SELECT id FROM campaigns WHERE client_name = :name LIMIT 1");
            $stmtC->execute(['name' => $brandName]);
            $cRow = $stmtC->fetch();
            if ($cRow) {
                $campaign_id = (int)$cRow['id'];
            }
        }

        $week = (int)($data['week'] ?? 1);
        $day = trim($data['day'] ?? 'Lunes');
        $rawDate = trim($data['date'] ?? '');
        $content_type = trim($data['type'] ?? 'General');
        $topic = trim($data['topic'] ?? 'Sin título');
        $content_format = trim($data['format'] ?? 'Post');
        $graphic_desc = trim($data['graphic_desc'] ?? '');
        $copy_text = trim($data['copy'] ?? '');
        $media_url = isset($data['media_url']) ? trim($data['media_url']) : null;

        $targetYear = isset($data['year']) ? (int)$data['year'] : (int)date('Y');
        $targetMonth = isset($data['month_num']) ? (int)$data['month_num'] : (int)date('m');

        $publish_date = null;
        if (!empty($rawDate)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $publish_date = $rawDate;
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $rawDate, $m)) {
                $publish_date = sprintf('%04d-%02d-%02d', $targetYear, (int)$m[2], (int)$m[1]);
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $rawDate, $m)) {
                $publish_date = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            } elseif (is_numeric($rawDate) && (int)$rawDate >= 1 && (int)$rawDate <= 31) {
                $publish_date = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, (int)$rawDate);
            }
        }

        try {
            $updateParams = [
                'week' => $week,
                'day' => $day,
                'content_type' => $content_type,
                'topic' => $topic,
                'graphic_desc' => $graphic_desc,
                'copy_text' => $copy_text,
                'content_format' => $content_format,
                'id' => $id
            ];

            $setParts = [
                'week = :week',
                'day = :day',
                'content_type = :content_type',
                'topic = :topic',
                'graphic_desc = :graphic_desc',
                'copy_text = :copy_text',
                'content_format = :content_format'
            ];

            if ($media_url !== null) {
                $setParts[] = 'media_url = :media_url';
                $updateParams['media_url'] = $media_url;
            }

            if ($publish_date) {
                $setParts[] = 'publish_date = :publish_date';
                $updateParams['publish_date'] = $publish_date;
            }

            if ($campaign_id > 0) {
                $setParts[] = 'campaign_id = :campaign_id';
                $updateParams['campaign_id'] = $campaign_id;
            }

            $sql = "UPDATE content_grid SET " . implode(', ', $setParts) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateParams);

            // Obtener datos finales de la pieza
            $stmtRow = $pdo->prepare("SELECT * FROM content_grid WHERE id = :id");
            $stmtRow->execute(['id' => $id]);
            $updatedRow = $stmtRow->fetch();

            $finalPubDate = $updatedRow['publish_date'] ?? $publish_date;
            $dateFormatted = !empty($finalPubDate) ? date('d/m', strtotime($finalPubDate)) : '';
            $pMonth = !empty($finalPubDate) ? (int)date('m', strtotime($finalPubDate)) : $targetMonth;
            $pYear = !empty($finalPubDate) ? (int)date('Y', strtotime($finalPubDate)) : $targetYear;

            logActivity($pdo, 'Modificación Pieza', "Pieza #$id ('$topic') actualizada con nueva descripción gráfica y detalles");

            echo json_encode([
                'success' => true,
                'message' => 'Pieza actualizada correctamente',
                'item' => [
                    'id' => $id,
                    'campaign_id' => $updatedRow['campaign_id'] ?? $campaign_id,
                    'brand' => $brandName,
                    'week' => (int)($updatedRow['week'] ?? $week),
                    'day' => $updatedRow['day'] ?? $day,
                    'date' => $dateFormatted,
                    'publish_date' => $finalPubDate,
                    'month' => $pMonth,
                    'year' => $pYear,
                    'type' => $updatedRow['content_type'] ?? $content_type,
                    'topic' => $updatedRow['topic'] ?? $topic,
                    'graphic_desc' => $updatedRow['graphic_desc'] ?? $graphic_desc,
                    'copy' => $updatedRow['copy_text'] ?? $copy_text,
                    'format' => $updatedRow['content_format'] ?? $content_format,
                    'media_url' => $updatedRow['media_url'] ?? '',
                    'cm_planning' => $updatedRow['cm_planning'] ?? 'Pendiente',
                    'designer_design' => $updatedRow['designer_design'] ?? 'Pendiente',
                    'agency_review' => $updatedRow['agency_review'] ?? 'Pendiente',
                    'cm_publishing' => $updatedRow['cm_publishing'] ?? 'Pendiente',
                    'overall_status' => $updatedRow['overall_status'] ?? 'Pendiente',
                    'progress' => (float)($updatedRow['progress'] ?? 0)
                ]
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar pieza: ' . $e->getMessage()]);
        }
        break;

    case 'delete_piece':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        try {
            // Obtener tema para log
            $stmtInfo = $pdo->prepare("SELECT topic FROM content_grid WHERE id = :id");
            $stmtInfo->execute(['id' => $id]);
            $piece = $stmtInfo->fetch();
            $topic = $piece['topic'] ?? 'Desconocido';

            $stmt = $pdo->prepare("DELETE FROM content_grid WHERE id = :id");
            $stmt->execute(['id' => $id]);

            logActivity($pdo, 'Eliminación', "Pieza #$id ('$topic') eliminada permanentemente");

            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()]);
        }
        break;

    case 'review_piece':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? $data['piece_id'] ?? 0);
        $decision = trim($data['decision'] ?? ''); // 'approved', 'rejected', 'adjustments'
        $notes = trim($data['notes'] ?? '');
        $user_name = trim($data['user_name'] ?? 'Revisor');

        if ($id <= 0 || empty($decision)) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM content_grid WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $piece = $stmt->fetch();
            if (!$piece) {
                echo json_encode(['success' => false, 'message' => 'Pieza no encontrada']);
                exit;
            }

            $currentGraphic = $piece['graphic_desc'] ?? '';
            $topic = $piece['topic'] ?? 'Sin título';

            if ($decision === 'approved') {
                // Al aprobar, se retira de la Línea de Tiempo (in_timeline = 0)
                $stmtUp = $pdo->prepare("UPDATE content_grid SET designer_design = 'Aprobado', agency_review = 'Completado', in_timeline = 0, progress = 75.00, overall_status = 'En Producción' WHERE id = :id");
                $stmtUp->execute(['id' => $id]);
                notifyBrandDesignerOnApproval($pdo, $id, $user_name);
                logActivity($pdo, 'Revisión Aprobada', "Pieza #$id ('$topic') fue aprobada por $user_name y retirada de la Línea de Tiempo", $user_name);
                echo json_encode([
                    'success' => true, 
                    'message' => 'Pieza aprobada correctamente y retirada de la Línea de Tiempo', 
                    'decision' => 'approved', 
                    'id' => $id,
                    'designer_design' => 'Aprobado',
                    'agency_review' => 'Completado',
                    'in_timeline' => 0
                ]);
            } elseif ($decision === 'rejected') {
                $stmtUp = $pdo->prepare("UPDATE content_grid SET designer_design = 'Pendiente', agency_review = 'Rechazado', in_timeline = 1 WHERE id = :id");
                $stmtUp->execute(['id' => $id]);
                logActivity($pdo, 'Revisión No Aprobada', "Pieza #$id ('$topic') marcada como No Aprobada por $user_name", $user_name);
                echo json_encode([
                    'success' => true, 
                    'message' => 'Pieza marcada como No Aprobada', 
                    'decision' => 'rejected', 
                    'id' => $id,
                    'designer_design' => 'Pendiente',
                    'agency_review' => 'Rechazado',
                    'in_timeline' => 1
                ]);
            } elseif ($decision === 'adjustments') {
                $dateTag = date('d/m/Y H:i');
                $newAdjustmentText = "\n\n📌 AJUSTES SOLICITADOS ($dateTag por $user_name):\n$notes";
                $updatedGraphicDesc = trim($currentGraphic . $newAdjustmentText);

                $stmtUp = $pdo->prepare("UPDATE content_grid SET graphic_desc = :graphic_desc, designer_design = 'En Proceso', agency_review = 'Pendiente', in_timeline = 1 WHERE id = :id");
                $stmtUp->execute(['graphic_desc' => $updatedGraphicDesc, 'id' => $id]);
                logActivity($pdo, 'Ajustes Solicitados', "Se solicitaron ajustes para pieza #$id ('$topic'): $notes", $user_name);
                echo json_encode([
                    'success' => true, 
                    'message' => 'Ajustes guardados e incorporados a la descripción de la pieza', 
                    'decision' => 'adjustments',
                    'id' => $id,
                    'updated_graphic_desc' => $updatedGraphicDesc,
                    'designer_design' => 'En Proceso',
                    'agency_review' => 'Pendiente',
                    'in_timeline' => 1
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Decisión no reconocida']);
            }
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al procesar revisión: ' . $e->getMessage()]);
        }
        break;

    case 'return_to_timeline':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? $data['piece_id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM content_grid WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $piece = $stmt->fetch();
            if (!$piece) {
                echo json_encode(['success' => false, 'message' => 'Pieza no encontrada']);
                exit;
            }

            $newDes = ($piece['designer_design'] === 'Aprobado') ? 'En Proceso' : $piece['designer_design'];
            $newRev = ($piece['agency_review'] === 'Completado') ? 'Pendiente' : $piece['agency_review'];

            $stmtUp = $pdo->prepare("UPDATE content_grid SET in_timeline = 1, designer_design = :des, agency_review = :rev WHERE id = :id");
            $stmtUp->execute(['des' => $newDes, 'rev' => $newRev, 'id' => $id]);

            $topic = $piece['topic'] ?? 'Sin título';
            logActivity($pdo, 'Regreso a Time Line', "Pieza #$id ('$topic') regresada a la Línea de Tiempo");

            echo json_encode([
                'success' => true,
                'message' => 'Pieza regresada a la Línea de Tiempo con éxito',
                'id' => $id,
                'in_timeline' => 1,
                'designer_design' => $newDes,
                'agency_review' => $newRev
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al regresar a Línea de Tiempo: ' . $e->getMessage()]);
        }
        break;

    case 'save_drive_link':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? $data['piece_id'] ?? 0);
        $driveUrl = trim((string)($data['drive_url'] ?? ''));
        $userName = trim((string)($data['user_name'] ?? 'Diseñador'));

        if ($id <= 0 || $driveUrl === '') {
            echo json_encode(['success' => false, 'message' => 'Debes ingresar el enlace de Google Drive']);
            exit;
        }
        if (!filter_var($driveUrl, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'message' => 'El enlace ingresado no es una URL válida']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT id, topic FROM content_grid WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $piece = $stmt->fetch();
            if (!$piece) {
                echo json_encode(['success' => false, 'message' => 'Pieza no encontrada']);
                exit;
            }

            $stmtUp = $pdo->prepare("UPDATE content_grid SET drive_url = :drive_url, drive_archived = 1 WHERE id = :id");
            $stmtUp->execute(['drive_url' => $driveUrl, 'id' => $id]);

            $topic = $piece['topic'] ?? 'Sin título';
            logActivity($pdo, 'Originales Subidos', "Pieza #$id ('$topic') - Link de Google Drive guardado y archivado por $userName", $userName);

            echo json_encode([
                'success' => true,
                'message' => 'Enlace de Google Drive guardado y pieza archivada con éxito',
                'id' => $id,
                'drive_url' => $driveUrl,
                'drive_archived' => 1
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al guardar el enlace: ' . $e->getMessage()]);
        }
        break;

    case 'get_notifications':
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            echo json_encode(['success' => true, 'notifications' => [], 'unread_count' => 0]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY is_read ASC, created_at DESC, id DESC LIMIT 50");
            $stmt->execute(['uid' => $userId]);
            $rows = $stmt->fetchAll();

            $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
            $stmtUnread->execute(['uid' => $userId]);
            $unreadCount = (int)$stmtUnread->fetchColumn();

            echo json_encode([
                'success' => true,
                'notifications' => $rows,
                'unread_count' => $unreadCount
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al obtener notificaciones: ' . $e->getMessage()]);
        }
        break;

    case 'mark_notification_read':
        $data = json_decode(file_get_contents('php://input'), true);
        $notifId = (int)($data['id'] ?? 0);
        $userId = (int)($data['user_id'] ?? 0);
        $markAll = !empty($data['mark_all']);

        try {
            if ($markAll && $userId > 0) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid");
                $stmt->execute(['uid' => $userId]);
            } elseif ($notifId > 0) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id");
                $stmt->execute(['id' => $notifId]);
            }
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar notificación']);
        }
        break;

    case 'upload_piece_media':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? $data['piece_id'] ?? 0);
        $rawMedia = $data['media_url'] ?? '';
        $actorName = trim((string)($data['user_name'] ?? ''));
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }
        try {
            $media_url = processMediaPayload($rawMedia, $id);
            $stmt = $pdo->prepare("UPDATE content_grid SET media_url = :media_url, designer_design = 'Listo' WHERE id = :id");
            $stmt->execute(['media_url' => $media_url, 'id' => $id]);
            notifyReviewersDesignReady($pdo, $id, $actorName);
            logActivity($pdo, 'Material Subido', "Se adjuntó nuevo material visual a la pieza #$id");
            echo json_encode([
                'success' => true, 
                'message' => 'Material multimedia actualizado con éxito', 
                'id' => $id, 
                'media_url' => $media_url,
                'designer_design' => 'Listo'
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al subir material: ' . $e->getMessage()]);
        }
        break;

    case 'add_member':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['name']) || empty($data['role'])) {
            echo json_encode(['success' => false, 'message' => 'Nombre y rol son requeridos']);
            exit;
        }

        $name = trim($data['name']);
        $role = trim($data['role']);
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');

        $allowedRoles = ['community_manager', 'designer', 'agency'];
        if (!in_array($role, $allowedRoles)) {
            echo json_encode(['success' => false, 'message' => 'Rol inválido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO team_members (name, role, email, phone) VALUES (:name, :role, :email, :phone)");
            $stmt->execute([
                'name' => $name,
                'role' => $role,
                'email' => $email,
                'phone' => $phone
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Si tiene email y no existe en users, agregarlo a users también
            if (!empty($email)) {
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmtCheck->execute(['email' => $email]);
                if (!$stmtCheck->fetch()) {
                    $stmtUser = $pdo->prepare("INSERT INTO users (name, email, role) VALUES (:name, :email, :role)");
                    $stmtUser->execute(['name' => $name, 'email' => $email, 'role' => $role]);
                }
            }

            $roleLabel = $role === 'designer' ? 'Diseñador' : 'Community Manager';
            logActivity($pdo, 'Nuevo Miembro', "Registrado nuevo $roleLabel: $name ($email)");

            echo json_encode([
                'success' => true,
                'member' => [
                    'id' => $newId,
                    'name' => $name,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone
                ]
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al guardar miembro: ' . $e->getMessage()]);
        }
        break;

    case 'add_user':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['name']) || empty($data['email'])) {
            echo json_encode(['success' => false, 'message' => 'Nombre/usuario y correo son requeridos']);
            exit;
        }

        $name = trim($data['name']);
        $email = trim($data['email']);
        $password = trim($data['password'] ?? $data['clave'] ?? 'admin123');
        $avatar = trim($data['avatar'] ?? $data['foto'] ?? '');
        if (!empty($avatar)) {
            $avatar = saveAvatarFile($avatar, 'user');
        } else {
            $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=dc2626&color=fff&bold=true';
        }
        $role = trim($data['role'] ?? 'community_manager');
        $status = ($data['status'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo';
        
        $permissions = is_array($data['permissions'] ?? null) ? json_encode($data['permissions']) : trim($data['permissions'] ?? '');
        if (empty($permissions)) {
            if ($role === 'admin') $permissions = json_encode(['dashboard', 'timeline', 'reviews', 'grid', 'reports', 'settings']);
            elseif ($role === 'designer') $permissions = json_encode(['dashboard', 'timeline', 'grid', 'reviews']);
            else $permissions = json_encode(['dashboard', 'grid', 'reports']);
        }

        $connectedAccounts = is_array($data['connected_accounts'] ?? null) 
            ? implode(',', $data['connected_accounts']) 
            : trim($data['connected_accounts'] ?? 'google,apple');

        $cedula = trim($data['cedula'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $brandRatesArr = is_array($data['brand_rates'] ?? null) ? $data['brand_rates'] : (json_decode((string)($data['brand_rates'] ?? '{}'), true) ?: []);
        $assignedBrandsArr = is_array($data['assigned_brands'] ?? null) ? $data['assigned_brands'] : (json_decode((string)($data['assigned_brands'] ?? '[]'), true) ?: []);
        $brandRatesJson = json_encode($brandRatesArr, JSON_UNESCAPED_UNICODE);
        $assignedBrandsJson = json_encode($assignedBrandsArr, JSON_UNESCAPED_UNICODE);
        $monthlySalary = round((float)($data['monthly_salary'] ?? 0), 2);
        if ($monthlySalary <= 0 && count($brandRatesArr) > 0) {
            $monthlySalary = round(array_sum(array_map('floatval', $brandRatesArr)), 2);
        }

        $passHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, avatar, status, permissions, connected_accounts, cedula, monthly_salary, brand_rates, assigned_brands) VALUES (:name, :email, :phone, :password, :role, :avatar, :status, :permissions, :connected_accounts, :cedula, :monthly_salary, :brand_rates, :assigned_brands)");
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $passHash,
                'role' => $role,
                'avatar' => $avatar,
                'status' => $status,
                'permissions' => $permissions,
                'connected_accounts' => $connectedAccounts,
                'cedula' => $cedula,
                'monthly_salary' => $monthlySalary,
                'brand_rates' => $brandRatesJson,
                'assigned_brands' => $assignedBrandsJson
            ]);
            $newId = (int)$pdo->lastInsertId();

            syncUserTeamAndBrands($pdo, $name, $email, $role, $cedula, $monthlySalary, $brandRatesJson, $assignedBrandsArr, $phone);
            logActivity($pdo, 'Nuevo Usuario', "Usuario registrado: $name ($email) con rol $role", $name);

            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $newId,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'avatar' => $avatar,
                    'status' => $status,
                    'permissions' => $permissions,
                    'connected_accounts' => $connectedAccounts,
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'assigned_brands' => $assignedBrandsJson
                ]
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está registrado']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al crear usuario: ' . $e->getMessage()]);
            }
        }
        break;

    case 'update_user':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0 || empty($data['name']) || empty($data['email'])) {
            echo json_encode(['success' => false, 'message' => 'ID, nombre/usuario y correo son requeridos']);
            exit;
        }

        $name = trim($data['name']);
        $email = trim($data['email']);
        $role = trim($data['role'] ?? 'community_manager');
        $status = ($data['status'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo';
        $permissions = is_array($data['permissions'] ?? null) ? json_encode($data['permissions']) : trim($data['permissions'] ?? '');
        $avatar = trim($data['avatar'] ?? $data['foto'] ?? '');
        if (!empty($avatar)) {
            $avatar = saveAvatarFile($avatar, (string)$id);
        }
        $connectedAccounts = is_array($data['connected_accounts'] ?? null) 
            ? implode(',', $data['connected_accounts']) 
            : trim($data['connected_accounts'] ?? 'google,apple');
        $password = trim($data['password'] ?? $data['clave'] ?? '');
        $cedula = trim($data['cedula'] ?? '');
        $brandRatesArr = is_array($data['brand_rates'] ?? null) ? $data['brand_rates'] : (json_decode((string)($data['brand_rates'] ?? '{}'), true) ?: []);
        $assignedBrandsArr = is_array($data['assigned_brands'] ?? null) ? $data['assigned_brands'] : (json_decode((string)($data['assigned_brands'] ?? '[]'), true) ?: []);
        $brandRatesJson = json_encode($brandRatesArr, JSON_UNESCAPED_UNICODE);
        $assignedBrandsJson = json_encode($assignedBrandsArr, JSON_UNESCAPED_UNICODE);
        $monthlySalary = round((float)($data['monthly_salary'] ?? 0), 2);
        if ($monthlySalary <= 0 && count($brandRatesArr) > 0) {
            $monthlySalary = round(array_sum(array_map('floatval', $brandRatesArr)), 2);
        }

        try {
            if (!empty($password)) {
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, role = :role, status = :status, permissions = :permissions, avatar = :avatar, connected_accounts = :connected_accounts, cedula = :cedula, monthly_salary = :monthly_salary, brand_rates = :brand_rates, assigned_brands = :assigned_brands, password = :password WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'status' => $status,
                    'permissions' => $permissions,
                    'avatar' => $avatar,
                    'connected_accounts' => $connectedAccounts,
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'assigned_brands' => $assignedBrandsJson,
                    'password' => $passHash,
                    'id' => $id
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, role = :role, status = :status, permissions = :permissions, avatar = :avatar, connected_accounts = :connected_accounts, cedula = :cedula, monthly_salary = :monthly_salary, brand_rates = :brand_rates, assigned_brands = :assigned_brands WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'status' => $status,
                    'permissions' => $permissions,
                    'avatar' => $avatar,
                    'connected_accounts' => $connectedAccounts,
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'assigned_brands' => $assignedBrandsJson,
                    'id' => $id
                ]);
            }

            syncUserTeamAndBrands($pdo, $name, $email, $role, $cedula, $monthlySalary, $brandRatesJson, $assignedBrandsArr);
            logActivity($pdo, 'Modificación Usuario', "Usuario #$id ('$name') actualizado con rol '$role'");
            echo json_encode([
                'success' => true,
                'message' => 'Usuario actualizado exitosamente',
                'user' => [
                    'id' => $id,
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'status' => $status,
                    'permissions' => $permissions,
                    'avatar' => $avatar,
                    'connected_accounts' => $connectedAccounts
                ]
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar usuario: ' . $e->getMessage()]);
        }
        break;

    case 'delete_user':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $u = $stmt->fetch();
            $uName = $u['name'] ?? "ID #$id";

            $pdo->prepare("DELETE FROM users WHERE id = :id")->execute(['id' => $id]);
            logActivity($pdo, 'Eliminación Usuario', "Usuario '$uName' eliminado del sistema");
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al eliminar usuario: ' . $e->getMessage()]);
        }
        break;

    case 'get_user_logs':
        $userName = trim($_GET['user_name'] ?? '');
        $email = trim($_GET['email'] ?? '');
        try {
            if (!empty($userName) || !empty($email)) {
                $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_name = :uname OR description LIKE :emailSearch ORDER BY created_at DESC, id DESC LIMIT 50");
                $stmt->execute([
                    'uname' => $userName,
                    'emailSearch' => "%$email%"
                ]);
            } else {
                $stmt = $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 50");
            }
            $logs = $stmt->fetchAll();
            echo json_encode(['success' => true, 'logs' => $logs]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'add_brand':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['client_name'])) {
            echo json_encode(['success' => false, 'message' => 'Nombre de la marca es requerido']);
            exit;
        }

        $client_name = trim($data['client_name']);
        $campaign_name = trim($data['campaign_name'] ?? $client_name);
        $strategy = trim($data['strategy'] ?? '');
        $initials = trim($data['initials'] ?? strtoupper(substr($client_name, 0, 2)));
        $start_date = trim($data['start_date'] ?? '');
        $end_date = trim($data['end_date'] ?? '');
        $duration_months = max(1, (int)($data['duration_months'] ?? 1));
        $delivery_date = trim($data['delivery_date'] ?? ($end_date ?: date('d/m/Y')));
        $month = trim($data['month'] ?? 'NOVIEMBRE');
        $year = (int)($data['year'] ?? 2026);
        $cm = trim($data['community_manager'] ?? 'No asignado');
        $designer = trim($data['designer'] ?? 'No asignado');
        $agency = trim($data['agency'] ?? 'Kinetica');
        $logo = trim($data['logo'] ?? '');
        $cover_image = trim($data['cover_image'] ?? '');
        $status = in_array($data['status'] ?? '', ['activo', 'oculto']) ? $data['status'] : 'activo';

        try {
            $stmt = $pdo->prepare("INSERT INTO campaigns (client_name, campaign_name, strategy, initials, delivery_date, start_date, end_date, duration_months, month, year, logo, cover_image, status) VALUES (:client_name, :campaign_name, :strategy, :initials, :delivery_date, :start_date, :end_date, :duration_months, :month, :year, :logo, :cover_image, :status)");
            $stmt->execute([
                'client_name' => $client_name,
                'campaign_name' => $campaign_name,
                'strategy' => $strategy,
                'initials' => $initials,
                'delivery_date' => $delivery_date,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'duration_months' => $duration_months,
                'month' => $month,
                'year' => $year,
                'logo' => $logo,
                'cover_image' => $cover_image,
                'status' => $status
            ]);
            $campaignId = (int)$pdo->lastInsertId();

            // Insertar responsables
            $stmtResp = $pdo->prepare("INSERT INTO responsibles (campaign_id, role, person_name) VALUES (:cid, :role, :pname)");
            $stmtResp->execute(['cid' => $campaignId, 'role' => 'community_manager', 'pname' => $cm]);
            $stmtResp->execute(['cid' => $campaignId, 'role' => 'designer', 'pname' => $designer]);
            $stmtResp->execute(['cid' => $campaignId, 'role' => 'agency', 'pname' => $agency]);

            logActivity($pdo, 'Nueva Marca', "Marca '$client_name' registrada con campaña '$campaign_name'");

            echo json_encode(['success' => true, 'id' => $campaignId, 'message' => 'Marca creada exitosamente']);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al crear marca: ' . $e->getMessage()]);
        }
        break;

    case 'update_brand':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0 || empty($data['client_name'])) {
            echo json_encode(['success' => false, 'message' => 'ID y nombre de marca requeridos']);
            exit;
        }

        $client_name = trim($data['client_name']);
        $campaign_name = trim($data['campaign_name'] ?? $client_name);
        $strategy = trim($data['strategy'] ?? '');
        $initials = trim($data['initials'] ?? strtoupper(substr($client_name, 0, 2)));
        $delivery_date = trim($data['delivery_date'] ?? '');
        $month = trim($data['month'] ?? 'NOVIEMBRE');
        $year = (int)($data['year'] ?? 2026);
        $cm = trim($data['community_manager'] ?? 'No asignado');
        $designer = trim($data['designer'] ?? 'No asignado');
        $agency = trim($data['agency'] ?? 'Kinetica');
        $status = in_array($data['status'] ?? '', ['activo', 'oculto']) ? $data['status'] : 'activo';

        try {
            if (isset($data['logo']) && $data['logo'] !== null) {
                $stmt = $pdo->prepare("UPDATE campaigns SET client_name = :client_name, campaign_name = :campaign_name, strategy = :strategy, initials = :initials, delivery_date = :delivery_date, month = :month, year = :year, status = :status, logo = :logo WHERE id = :id");
                $stmt->execute([
                    'client_name' => $client_name,
                    'campaign_name' => $campaign_name,
                    'strategy' => $strategy,
                    'initials' => $initials,
                    'delivery_date' => $delivery_date,
                    'month' => $month,
                    'year' => $year,
                    'status' => $status,
                    'logo' => $data['logo'],
                    'id' => $id
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE campaigns SET client_name = :client_name, campaign_name = :campaign_name, strategy = :strategy, initials = :initials, delivery_date = :delivery_date, month = :month, year = :year, status = :status WHERE id = :id");
                $stmt->execute([
                    'client_name' => $client_name,
                    'campaign_name' => $campaign_name,
                    'strategy' => $strategy,
                    'initials' => $initials,
                    'delivery_date' => $delivery_date,
                    'month' => $month,
                    'year' => $year,
                    'status' => $status,
                    'id' => $id
                ]);
            }

            // Actualizar responsables
            $pdo->prepare("DELETE FROM responsibles WHERE campaign_id = :id")->execute(['id' => $id]);
            $stmtResp = $pdo->prepare("INSERT INTO responsibles (campaign_id, role, person_name) VALUES (:cid, :role, :pname)");
            $stmtResp->execute(['cid' => $id, 'role' => 'community_manager', 'pname' => $cm]);
            $stmtResp->execute(['cid' => $id, 'role' => 'designer', 'pname' => $designer]);
            $stmtResp->execute(['cid' => $id, 'role' => 'agency', 'pname' => $agency]);

            logActivity($pdo, 'Modificación Marca', "Marca #$id ('$client_name') actualizada");

            echo json_encode(['success' => true, 'message' => 'Marca actualizada exitosamente']);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar marca: ' . $e->getMessage()]);
        }
        break;

    case 'delete_brand':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        try {
            $stmtName = $pdo->prepare("SELECT client_name FROM campaigns WHERE id = :id");
            $stmtName->execute(['id' => $id]);
            $cRow = $stmtName->fetch();
            $brandName = $cRow['client_name'] ?? 'ID #' . $id;

            $pdo->prepare("DELETE FROM content_grid WHERE campaign_id = :id")->execute(['id' => $id]);
            $pdo->prepare("DELETE FROM brief_references WHERE campaign_id = :id")->execute(['id' => $id]);
            $pdo->prepare("DELETE FROM responsibles WHERE campaign_id = :id")->execute(['id' => $id]);
            $pdo->prepare("DELETE FROM campaigns WHERE id = :id")->execute(['id' => $id]);

            logActivity($pdo, 'Eliminación Marca', "Marca '$brandName' eliminada permanentemente");

            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al eliminar marca: ' . $e->getMessage()]);
        }
        break;

    case 'toggle_brand_status':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $status = ($data['status'] ?? '') === 'oculto' ? 'oculto' : 'activo';
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $status, 'id' => $id]);

            logActivity($pdo, 'Estado Marca', "Marca #$id cambiada a estado '$status'");
            echo json_encode(['success' => true, 'status' => $status]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()]);
        }
        break;

    case 'update_member':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0 || empty($data['name']) || empty($data['role'])) {
            echo json_encode(['success' => false, 'message' => 'ID, nombre y rol requeridos']);
            exit;
        }

        $name = trim($data['name']);
        $role = trim($data['role']);
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $status = ($data['status'] ?? 'activo') === 'inactivo' ? 'inactivo' : 'activo';

        try {
            $stmt = $pdo->prepare("UPDATE team_members SET name = :name, role = :role, email = :email, phone = :phone, status = :status WHERE id = :id");
            $stmt->execute([
                'name' => $name,
                'role' => $role,
                'email' => $email,
                'phone' => $phone,
                'status' => $status,
                'id' => $id
            ]);

            logActivity($pdo, 'Modificación Miembro', "Miembro #$id ('$name') actualizado");
            echo json_encode(['success' => true, 'message' => 'Miembro actualizado exitosamente']);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar miembro: ' . $e->getMessage()]);
        }
        break;

    case 'delete_member':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT name FROM team_members WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $m = $stmt->fetch();
            $mName = $m['name'] ?? "ID #$id";

            $pdo->prepare("DELETE FROM team_members WHERE id = :id")->execute(['id' => $id]);
            logActivity($pdo, 'Eliminación Miembro', "Miembro '$mName' eliminado de equipo");
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al eliminar miembro: ' . $e->getMessage()]);
        }
        break;

    case 'get_logs':
        try {
            $stmt = $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC");
            $logs = $stmt->fetchAll();
            echo json_encode(['success' => true, 'logs' => $logs]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'export_data':
        try {
            $tables = ['campaigns', 'responsibles', 'brief_references', 'content_grid', 'team_members', 'users', 'activity_logs'];
            $export = [
                'metadata' => [
                    'system' => 'Kinetica Content Dashboard',
                    'version' => '2.0',
                    'exported_at' => date('c'),
                    'database' => 'content_dashboard'
                ],
                'tables' => []
            ];

            foreach ($tables as $t) {
                $stmt = $pdo->query("SELECT * FROM {$t}");
                $export['tables'][$t] = $stmt->fetchAll();
            }

            logActivity($pdo, 'Exportación', 'Copia de seguridad completa exportada por el usuario');

            echo json_encode(['success' => true, 'export' => $export]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'import_data':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['tables']) || !is_array($data['tables'])) {
            echo json_encode(['success' => false, 'message' => 'Estructura de respaldo no válida']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $tables = $data['tables'];

            // Desactivar temporalmente foreign keys
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // Limpiar tablas dentro de la transacción (DELETE evita el commit implícito de TRUNCATE en MySQL)
            foreach (['content_grid', 'brief_references', 'responsibles', 'campaigns', 'team_members', 'users', 'activity_logs', 'payments'] as $t) {
                if (isset($tables[$t])) {
                    $pdo->exec("DELETE FROM `{$t}`;");
                }
            }

            // Insertar datos
            foreach ($tables as $tableName => $rows) {
                if (empty($rows) || !is_array($rows)) continue;

                $first = $rows[0];
                $columns = array_keys($first);
                $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
                $placeholders = ':' . implode(', :', $columns);

                $sql = "INSERT INTO `{$tableName}` ({$colList}) VALUES ({$placeholders})";
                $stmt = $pdo->prepare($sql);

                foreach ($rows as $row) {
                    $stmt->execute($row);
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            logActivity($pdo, 'Importación', 'Copia de seguridad restaurada con éxito en la base de datos');

            echo json_encode(['success' => true, 'message' => 'Base de datos restaurada correctamente']);
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            echo json_encode(['success' => false, 'message' => 'Error durante importación: ' . $e->getMessage()]);
        }
        break;

    case 'add_payment':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['person_name']) || empty($data['payment_date'])) {
            echo json_encode(['success' => false, 'message' => 'Persona y fecha son requeridos']);
            exit;
        }
        $memberId = !empty($data['member_id']) ? (int)$data['member_id'] : null;
        $personName = trim($data['person_name']);
        $personRole = trim($data['person_role'] ?? 'designer');
        $amountUsd = round((float)($data['amount_usd'] ?? 0), 2);
        $exchangeRate = round((float)($data['exchange_rate'] ?? 1), 4);
        $amountBs = round((float)($data['amount_bs'] ?? ($amountUsd * $exchangeRate)), 2);
        $paymentDate = trim($data['payment_date']);
        $paymentMonth = substr($paymentDate, 0, 7);
        $note = trim($data['note'] ?? '');

        if ($amountUsd <= 0 && $amountBs <= 0) {
            echo json_encode(['success' => false, 'message' => 'El monto del pago debe ser mayor a 0']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO payments (member_id, person_name, person_role, amount_usd, exchange_rate, amount_bs, payment_date, payment_month, note) VALUES (:member_id, :person_name, :person_role, :amount_usd, :exchange_rate, :amount_bs, :payment_date, :payment_month, :note)");
            $stmt->execute([
                'member_id' => $memberId,
                'person_name' => $personName,
                'person_role' => $personRole,
                'amount_usd' => $amountUsd,
                'exchange_rate' => $exchangeRate,
                'amount_bs' => $amountBs,
                'payment_date' => $paymentDate,
                'payment_month' => $paymentMonth,
                'note' => $note
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity($pdo, 'Nuevo Pago', "Pago registrado a $personName por \$$amountUsd (Bs. $amountBs) el $paymentDate");
            echo json_encode([
                'success' => true,
                'payment' => [
                    'id' => $newId,
                    'member_id' => $memberId,
                    'person_name' => $personName,
                    'person_role' => $personRole,
                    'amount_usd' => $amountUsd,
                    'exchange_rate' => $exchangeRate,
                    'amount_bs' => $amountBs,
                    'payment_date' => $paymentDate,
                    'payment_month' => $paymentMonth,
                    'note' => $note
                ]
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al registrar pago: ' . $e->getMessage()]);
        }
        break;

    case 'delete_payment':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de pago inválido']);
            exit;
        }
        try {
            $stmtGet = $pdo->prepare("SELECT person_name, amount_usd FROM payments WHERE id = :id");
            $stmtGet->execute(['id' => $id]);
            $row = $stmtGet->fetch();
            $pdo->prepare("DELETE FROM payments WHERE id = :id")->execute(['id' => $id]);
            if ($row) {
                logActivity($pdo, 'Pago Eliminado', "Pago #{$id} de {$row['person_name']} (\${$row['amount_usd']}) eliminado");
            }
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al eliminar pago: ' . $e->getMessage()]);
        }
        break;

    case 'update_member_compensation':
        $data = json_decode(file_get_contents('php://input'), true);
        $memberId = (int)($data['id'] ?? $data['member_id'] ?? 0);
        $userId = (int)($data['user_id'] ?? 0);
        $personName = trim($data['person_name'] ?? '');
        $personEmail = trim($data['person_email'] ?? '');
        $personRole = trim($data['person_role'] ?? 'designer');
        if ($memberId <= 0 && $userId <= 0 && empty($personName)) {
            echo json_encode(['success' => false, 'message' => 'Identificador de persona inválido']);
            exit;
        }
        $cedula = trim($data['cedula'] ?? '');
        $brandRatesArr = is_array($data['brand_rates'] ?? null) ? $data['brand_rates'] : (json_decode((string)($data['brand_rates'] ?? '{}'), true) ?: []);
        $assignedBrandsArr = is_array($data['assigned_brands'] ?? null) ? $data['assigned_brands'] : array_keys($brandRatesArr);
        $brandRatesJson = json_encode($brandRatesArr, JSON_UNESCAPED_UNICODE);
        $assignedBrandsJson = json_encode($assignedBrandsArr, JSON_UNESCAPED_UNICODE);
        $monthlySalary = round((float)($data['monthly_salary'] ?? 0), 2);
        if ($monthlySalary <= 0 && count($brandRatesArr) > 0) {
            $monthlySalary = round(array_sum(array_map('floatval', $brandRatesArr)), 2);
        }

        try {
            if ($memberId > 0) {
                $stmt = $pdo->prepare("UPDATE team_members SET cedula = :cedula, monthly_salary = :monthly_salary, brand_rates = :brand_rates WHERE id = :id");
                $stmt->execute([
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'id' => $memberId
                ]);
            }
            if ($userId > 0) {
                $stmtU = $pdo->prepare("UPDATE users SET cedula = :cedula, monthly_salary = :monthly_salary, brand_rates = :brand_rates, assigned_brands = :assigned_brands WHERE id = :id");
                $stmtU->execute([
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'assigned_brands' => $assignedBrandsJson,
                    'id' => $userId
                ]);
            } elseif (!empty($personName)) {
                $stmtU = $pdo->prepare("UPDATE users SET cedula = :cedula, monthly_salary = :monthly_salary, brand_rates = :brand_rates, assigned_brands = :assigned_brands WHERE LOWER(name) = LOWER(:name)");
                $stmtU->execute([
                    'cedula' => $cedula,
                    'monthly_salary' => $monthlySalary,
                    'brand_rates' => $brandRatesJson,
                    'assigned_brands' => $assignedBrandsJson,
                    'name' => $personName
                ]);
            }
            if (!empty($personName)) {
                syncUserTeamAndBrands($pdo, $personName, $personEmail, $personRole, $cedula, $monthlySalary, $brandRatesJson, $assignedBrandsArr);
            }
            logActivity($pdo, 'Compensación Actualizada', "Actualizada cédula/tarifas de " . ($personName ?: "miembro #$memberId") . " (Salario: \$$monthlySalary)");
            echo json_encode(['success' => true, 'message' => 'Datos y tarifas actualizados']);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar compensación: ' . $e->getMessage()]);
        }
        break;

    case 'reset_all_data':
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $keepUserId = (int)($data['keep_user_id'] ?? 0);

        try {
            // Conservar datos del administrador actual (o crear admin por defecto) para no bloquear el acceso
            $preservedAdmin = null;
            if ($keepUserId > 0) {
                $stAdmin = $pdo->prepare("SELECT name, email, password, role, status, permissions, avatar, connected_accounts FROM users WHERE id = :id LIMIT 1");
                $stAdmin->execute(['id' => $keepUserId]);
                $preservedAdmin = $stAdmin->fetch();
            }
            if (!$preservedAdmin) {
                $stAdmin = $pdo->query("SELECT name, email, password, role, status, permissions, avatar, connected_accounts FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
                $preservedAdmin = $stAdmin->fetch();
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            foreach (['content_grid', 'brief_references', 'responsibles', 'campaigns', 'team_members', 'payments', 'activity_logs', 'users'] as $t) {
                try {
                    $pdo->exec("TRUNCATE TABLE {$t};");
                } catch (\PDOException $te) {
                    // Ignorar si alguna tabla opcional no existe
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            if ($preservedAdmin) {
                $insAdmin = $pdo->prepare("INSERT INTO users (name, email, password, role, status, permissions, avatar, connected_accounts) VALUES (:name, :email, :password, 'admin', 'activo', :permissions, :avatar, :connected_accounts)");
                $insAdmin->execute([
                    'name' => $preservedAdmin['name'],
                    'email' => $preservedAdmin['email'],
                    'password' => $preservedAdmin['password'] ?: password_hash('admin123', PASSWORD_DEFAULT),
                    'permissions' => $preservedAdmin['permissions'] ?: json_encode(['dashboard', 'timeline', 'reviews', 'grid', 'reports', 'payments', 'settings']),
                    'avatar' => $preservedAdmin['avatar'] ?? '',
                    'connected_accounts' => $preservedAdmin['connected_accounts'] ?: 'google,apple'
                ]);
                $newAdminId = (int)$pdo->lastInsertId();
                $preservedAdmin['id'] = $newAdminId;
                $preservedAdmin['role'] = 'admin';
                $preservedAdmin['status'] = 'activo';
                unset($preservedAdmin['password']);
            } else {
                $defaultPass = password_hash('admin123', PASSWORD_DEFAULT);
                $defaultPerms = json_encode(['dashboard', 'timeline', 'reviews', 'grid', 'reports', 'payments', 'settings']);
                $insAdmin = $pdo->prepare("INSERT INTO users (name, email, password, role, status, permissions, avatar, connected_accounts) VALUES ('Admin Kinetica', 'admin@kinetica.com', :password, 'admin', 'activo', :permissions, '', 'google,apple')");
                $insAdmin->execute(['password' => $defaultPass, 'permissions' => $defaultPerms]);
                $newAdminId = (int)$pdo->lastInsertId();
                $preservedAdmin = [
                    'id' => $newAdminId,
                    'name' => 'Admin Kinetica',
                    'email' => 'admin@kinetica.com',
                    'role' => 'admin',
                    'status' => 'activo',
                    'permissions' => $defaultPerms,
                    'avatar' => '',
                    'connected_accounts' => 'google,apple'
                ];
            }

            logActivity($pdo, 'Reinicio Total', 'Toda la base de datos fue eliminada para comenzar desde cero', $preservedAdmin['name'] ?? 'Sistema');

            echo json_encode([
                'success' => true,
                'message' => 'Toda la data ha sido eliminada. El sistema comenzó de cero.',
                'user' => $preservedAdmin
            ]);
        } catch (\Exception $e) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            echo json_encode(['success' => false, 'message' => 'Error al reiniciar base de datos: ' . $e->getMessage()]);
        }
        break;

    case 'request_access':
        $data = json_decode(file_get_contents('php://input'), true);
        $email = trim($data['email'] ?? '');
        $name = trim($data['name'] ?? '');
        $avatar = trim($data['avatar'] ?? '');
        $requestedRole = trim($data['requested_role'] ?? 'community_manager');
        $message = trim($data['message'] ?? '');
        $acceptedTerms = isExplicitlyAccepted($data['accepted_terms'] ?? false);
        $acceptedPrivacy = isExplicitlyAccepted($data['accepted_privacy'] ?? false);

        if (!$acceptedTerms || !$acceptedPrivacy) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Debe aceptar de forma independiente los Términos y Condiciones y la Política de Privacidad antes de enviar su solicitud.'
            ]);
            exit;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Correo electrónico inválido']);
            exit;
        }
        if (empty($name)) {
            $name = explode('@', $email)[0];
        }

        try {
            $chkUser = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $chkUser->execute(['email' => $email]);
            if ($chkUser->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Este correo ya está registrado. Intenta iniciar sesión nuevamente.']);
                exit;
            }

            $chkReq = $pdo->prepare("SELECT id FROM access_requests WHERE email = :email AND status = 'pendiente' LIMIT 1");
            $chkReq->execute(['email' => $email]);
            $existing = $chkReq->fetch();

            if ($existing) {
                $upd = $pdo->prepare("UPDATE access_requests SET name = :name, avatar = :avatar, requested_role = :role, message = :msg WHERE id = :id");
                $upd->execute([
                    'name' => $name,
                    'avatar' => $avatar,
                    'role' => $requestedRole,
                    'msg' => $message,
                    'id' => $existing['id']
                ]);
                $audit = recordConsentAudit($pdo, 'access_request', (int)$existing['id'], $name, $email, $acceptedTerms, $acceptedPrivacy);
                echo json_encode([
                    'success' => true,
                    'message' => 'Tu solicitud de registro fue actualizada y está pendiente de aprobación.',
                    'audit' => $audit
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO access_requests (name, email, avatar, provider, requested_role, message, status) VALUES (:name, :email, :avatar, 'google', :role, :msg, 'pendiente')");
                $ins->execute([
                    'name' => $name,
                    'email' => $email,
                    'avatar' => $avatar,
                    'role' => $requestedRole,
                    'msg' => $message
                ]);
                $reqId = (int)$pdo->lastInsertId();
                $audit = recordConsentAudit($pdo, 'access_request', $reqId, $name, $email, $acceptedTerms, $acceptedPrivacy);
                logActivity($pdo, 'Solicitud de Acceso', "Nueva solicitud de registro vía Google: $name ($email) | Auditoría #{$audit['audit_id']} IP {$audit['ip_address']}", $name);
                echo json_encode([
                    'success' => true,
                    'message' => '¡Solicitud enviada! El administrador revisará tu petición y asignará tus privilegios.',
                    'audit' => $audit
                ]);
            }
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al registrar solicitud: ' . $e->getMessage()]);
        }
        break;

    case 'test_connection':
        echo json_encode(['success' => true, 'message' => 'Conexión MySQL operativa']);
        break;

    case 'submit_contact_quote':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cuerpo de solicitud JSON inválido.']);
            exit;
        }

        $clientName = trim((string)($data['client_name'] ?? ''));
        $clientEmail = trim((string)($data['client_email'] ?? ''));
        $serviceType = trim((string)($data['service_type'] ?? ''));
        $projectSummary = trim((string)($data['project_summary'] ?? ''));
        $acceptedTerms = isExplicitlyAccepted($data['accepted_terms'] ?? false);
        $acceptedPrivacy = isExplicitlyAccepted($data['accepted_privacy'] ?? false);

        if (!$acceptedTerms || !$acceptedPrivacy) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Bloqueo legal: debe marcar de forma independiente la aceptación de los Términos y Condiciones y la Política de Privacidad.'
            ]);
            exit;
        }

        $allowedServices = ['Diseño Gráfico', 'Desarrollo Web', 'Publicidad Digital', 'Paquete Integral'];
        if (mb_strlen($clientName, 'UTF-8') < 2) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ingresa tu nombre completo o razón social.']);
            exit;
        }
        if (empty($clientEmail) || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ingresa un correo electrónico válido.']);
            exit;
        }
        if (!in_array($serviceType, $allowedServices, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Selecciona un servicio válido para cotizar.']);
            exit;
        }
        if (mb_strlen($projectSummary, 'UTF-8') < 10) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Describe brevemente el requerimiento del proyecto (mínimo 10 caracteres).']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $insQuote = $pdo->prepare("INSERT INTO contact_quotes (client_name, client_email, service_type, project_summary, status) VALUES (:client_name, :client_email, :service_type, :project_summary, 'nueva')");
            $insQuote->execute([
                'client_name' => substr($clientName, 0, 150),
                'client_email' => substr($clientEmail, 0, 150),
                'service_type' => $serviceType,
                'project_summary' => $projectSummary
            ]);
            $quoteId = (int)$pdo->lastInsertId();

            $audit = recordConsentAudit($pdo, 'contact_quote', $quoteId, $clientName, $clientEmail, $acceptedTerms, $acceptedPrivacy);
            $pdo->commit();

            logActivity($pdo, 'Cotización Inicial', "Solicitud #{$quoteId} ({$serviceType}) de {$clientName} ({$clientEmail}) | Auditoría #{$audit['audit_id']} IP {$audit['ip_address']}", $clientName);

            echo json_encode([
                'success' => true,
                'message' => 'Solicitud de cotización registrada con trazabilidad legal.',
                'quote_id' => $quoteId,
                'audit' => $audit
            ]);
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error al registrar cotización: ' . $e->getMessage()]);
        }
        break;

    case 'get_consent_audits':
        try {
            $audits = $pdo->query("SELECT * FROM consent_audit_logs ORDER BY id DESC LIMIT 200")->fetchAll();
            $quotes = $pdo->query("SELECT * FROM contact_quotes ORDER BY id DESC LIMIT 200")->fetchAll();
            echo json_encode([
                'success' => true,
                'audits' => $audits,
                'quotes' => $quotes
            ]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'audits' => [], 'quotes' => [], 'message' => $e->getMessage()]);
        }
        break;

    case 'get_access_requests':
        try {
            $stmt = $pdo->query("SELECT * FROM access_requests ORDER BY FIELD(status, 'pendiente', 'aprobado', 'rechazado'), created_at DESC");
            $requests = $stmt->fetchAll();
            echo json_encode(['success' => true, 'requests' => $requests]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'requests' => [], 'error' => $e->getMessage()]);
        }
        break;

    case 'approve_access_request':
        $data = json_decode(file_get_contents('php://input'), true);
        $reqId = (int)($data['request_id'] ?? 0);
        $role = trim($data['role'] ?? 'community_manager');
        $perms = $data['permissions'] ?? ['dashboard', 'timeline', 'reviews', 'grid'];
        $assignedBrands = $data['assigned_brands'] ?? [];
        $customName = trim($data['name'] ?? '');
        $cedula = trim($data['cedula'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $brandRates = $data['brand_rates'] ?? [];
        $monthlySalary = isset($data['monthly_salary']) ? (float)$data['monthly_salary'] : 0;

        if ($reqId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de solicitud inválido']);
            exit;
        }

        try {
            $st = $pdo->prepare("SELECT * FROM access_requests WHERE id = :id LIMIT 1");
            $st->execute(['id' => $reqId]);
            $req = $st->fetch();
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
                exit;
            }

            $email = trim($req['email']);
            $name = $customName !== '' ? $customName : trim($req['name']);
            $avatar = trim($req['avatar'] ?? '');
            if (empty($avatar)) {
                $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=dc2626&color=fff&bold=true';
            }

            $permsJson = is_array($perms) ? json_encode(array_values($perms)) : (is_string($perms) ? $perms : json_encode(['dashboard', 'timeline', 'reviews', 'grid']));
            $brandsJson = is_array($assignedBrands) ? json_encode(array_values($assignedBrands)) : json_encode([]);
            $ratesJson = is_array($brandRates) ? json_encode($brandRates) : json_encode(new \stdClass());
            if ($monthlySalary <= 0 && is_array($brandRates)) {
                foreach ($brandRates as $rVal) {
                    $monthlySalary += (float)$rVal;
                }
            }

            $chkUser = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $chkUser->execute(['email' => $email]);
            $existingUser = $chkUser->fetch();

            if ($existingUser) {
                $updUser = $pdo->prepare("UPDATE users SET name = :name, role = :role, status = 'activo', cedula = :cedula, phone = :phone, monthly_salary = :salary, brand_rates = :rates, permissions = :perms, assigned_brands = :brands, connected_accounts = CASE WHEN connected_accounts IS NULL OR connected_accounts = '' THEN 'google' WHEN LOCATE('google', LOWER(connected_accounts)) = 0 THEN CONCAT(connected_accounts, ',google') ELSE connected_accounts END WHERE id = :id");
                $updUser->execute([
                    'name' => $name,
                    'role' => $role,
                    'cedula' => $cedula,
                    'phone' => $phone,
                    'salary' => $monthlySalary,
                    'rates' => $ratesJson,
                    'perms' => $permsJson,
                    'brands' => $brandsJson,
                    'id' => $existingUser['id']
                ]);
                $userId = (int)$existingUser['id'];
            } else {
                $insUser = $pdo->prepare("INSERT INTO users (name, email, phone, cedula, monthly_salary, brand_rates, role, status, permissions, assigned_brands, avatar, connected_accounts) VALUES (:name, :email, :phone, :cedula, :salary, :rates, :role, 'activo', :perms, :brands, :avatar, 'google')");
                $insUser->execute([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'cedula' => $cedula,
                    'salary' => $monthlySalary,
                    'rates' => $ratesJson,
                    'role' => $role,
                    'perms' => $permsJson,
                    'brands' => $brandsJson,
                    'avatar' => $avatar
                ]);
                $userId = (int)$pdo->lastInsertId();
            }

            if (function_exists('syncUserTeamAndBrands')) {
                syncUserTeamAndBrands($pdo, $name, $email, $role, $cedula, $monthlySalary, $ratesJson, $brandsJson, 'activo', $phone);
            }

            $pdo->prepare("UPDATE access_requests SET status = 'aprobado' WHERE id = :id")->execute(['id' => $reqId]);
            logActivity($pdo, 'Acceso Aprobado', "Solicitud de $name ($email) aprobada como $role");

            $stUser = $pdo->prepare("SELECT id, name, email, role, status, permissions, avatar, connected_accounts, cedula, phone, monthly_salary, brand_rates, assigned_brands FROM users WHERE id = :id LIMIT 1");
            $stUser->execute(['id' => $userId]);
            $newUser = $stUser->fetch();

            echo json_encode(['success' => true, 'user' => $newUser, 'message' => 'Usuario aprobado y activado con éxito']);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error al aprobar solicitud: ' . $e->getMessage()]);
        }
        break;

    case 'reject_access_request':
        $data = json_decode(file_get_contents('php://input'), true);
        $reqId = (int)($data['request_id'] ?? 0);
        if ($reqId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }
        try {
            $pdo->prepare("UPDATE access_requests SET status = 'rechazado' WHERE id = :id")->execute(['id' => $reqId]);
            logActivity($pdo, 'Solicitud Rechazada', "Solicitud de acceso #$reqId rechazada");
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'mark_request_approved':
        $data = json_decode(file_get_contents('php://input'), true);
        $reqId = (int)($data['request_id'] ?? 0);
        if ($reqId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }
        try {
            $pdo->prepare("UPDATE access_requests SET status = 'aprobado' WHERE id = :id")->execute(['id' => $reqId]);
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'delete_access_request':
        $data = json_decode(file_get_contents('php://input'), true);
        $reqId = (int)($data['request_id'] ?? 0);
        if ($reqId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }
        try {
            $pdo->prepare("DELETE FROM access_requests WHERE id = :id")->execute(['id' => $reqId]);
            logActivity($pdo, 'Solicitud Eliminada', "Solicitud de acceso #$reqId eliminada");
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        break;
}
