<?php
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !isset($data['id'], $data['field'], $data['value'])) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

$id = (int)$data['id'];
$field = $data['field'];
$value = $data['value'];

$allowed_fields = ['cm_planning', 'designer_design', 'agency_review', 'cm_publishing', 'observations', 'copy_text', 'topic'];
if (!in_array($field, $allowed_fields)) {
    echo json_encode(['success' => false, 'message' => 'Campo no permitido']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE content_grid SET {$field} = :value WHERE id = :id");
    $stmt->execute(['value' => $value, 'id' => $id]);

    $stmt = $pdo->prepare("SELECT cm_planning, designer_design, agency_review, cm_publishing FROM content_grid WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        $map = ['Pendiente' => 0, 'En Proceso' => 50, 'Completado' => 100];
        $total = ($map[$row['cm_planning']] ?? 0) + 
                 ($map[$row['designer_design']] ?? 0) + 
                 ($map[$row['agency_review']] ?? 0) + 
                 ($map[$row['cm_publishing']] ?? 0);
        $progress = $total / 4;
        $overall_status = $progress == 100 ? 'Publicado' : ($progress > 0 ? 'En Producción' : 'Pendiente');

        $updateProg = $pdo->prepare("UPDATE content_grid SET progress = :prog, overall_status = :status WHERE id = :id");
        $updateProg->execute(['prog' => $progress, 'status' => $overall_status, 'id' => $id]);

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