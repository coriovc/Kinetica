-- Base de datos y configuraciones iniciales
CREATE DATABASE IF NOT EXISTS content_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE content_dashboard;

-- Tabla de Campañas / Marcas
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(100) NOT NULL,
    month VARCHAR(20) NOT NULL,
    year INT NOT NULL,
    campaign_name VARCHAR(150) NOT NULL,
    strategy TEXT,
    initials VARCHAR(10) DEFAULT '',
    delivery_date VARCHAR(50) DEFAULT '',
    logo MEDIUMTEXT,
    status ENUM('activo', 'oculto') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Responsables
CREATE TABLE IF NOT EXISTS responsibles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    role ENUM('community_manager', 'designer', 'agency') NOT NULL,
    person_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Miembros del Equipo (Diseñadores y Community Managers)
CREATE TABLE IF NOT EXISTS team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    role ENUM('community_manager', 'designer', 'agency') NOT NULL,
    email VARCHAR(150) DEFAULT '',
    phone VARCHAR(50) DEFAULT '',
    status ENUM('activo', 'inactivo') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Usuarios del Sistema
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    role VARCHAR(50) NOT NULL DEFAULT 'community_manager',
    status ENUM('activo', 'inactivo') DEFAULT 'activo',
    permissions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Registro de Actividades (Auditoría)
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    user_name VARCHAR(100) DEFAULT 'Sistema',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Cotizaciones Iniciales (Minimización de Datos)
CREATE TABLE IF NOT EXISTS contact_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(150) NOT NULL,
    client_email VARCHAR(150) NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    project_summary TEXT NOT NULL,
    status ENUM('nueva', 'cotizada', 'archivada') DEFAULT 'nueva',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Auditoría de Consentimiento Legal (Trazabilidad Fecha, Hora, IP y Checkboxes)
CREATE TABLE IF NOT EXISTS consent_audit_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Briefs / Requerimientos de Diseño
CREATE TABLE IF NOT EXISTS brief_references (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    content TEXT,
    format VARCHAR(100),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de Grilla y Cumplimiento
CREATE TABLE IF NOT EXISTS content_grid (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    week INT NOT NULL,
    day VARCHAR(20) NOT NULL,
    publish_date DATE NOT NULL,
    content_type VARCHAR(100),
    topic VARCHAR(200),
    graphic_desc TEXT,
    copy_text TEXT,
    content_format VARCHAR(50),
    media_url LONGTEXT,
    
    -- Fases de Cumplimiento
    cm_planning VARCHAR(50) DEFAULT 'Pendiente',
    designer_design VARCHAR(50) DEFAULT 'Pendiente',
    agency_review VARCHAR(50) DEFAULT 'Pendiente',
    cm_publishing VARCHAR(50) DEFAULT 'Pendiente',
    
    -- Cálculos generales
    overall_status VARCHAR(50) DEFAULT 'Pendiente',
    progress DECIMAL(5,2) DEFAULT 0.00,
    observations TEXT,
    in_timeline TINYINT(1) DEFAULT 1,
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserción de Datos Iniciales

-- 1. Liquid Glass V2
INSERT INTO campaigns (id, client_name, month, year, campaign_name, strategy, initials, delivery_date) VALUES
(1, 'Liquid Glass V2', 'NOVIEMBRE', 2026, 'Liquid Glass V2', 'Campaña de posicionamiento del entorno visual y diseño de alta gama.', 'LG', '01/11/2026')
ON DUPLICATE KEY UPDATE client_name=VALUES(client_name);

INSERT INTO responsibles (campaign_id, role, person_name) VALUES
(1, 'community_manager', 'Valentina Almeida'),
(1, 'designer', 'Victor Corio'),
(1, 'agency', 'Kinetica');

INSERT INTO brief_references (campaign_id, title, format, content) VALUES
(1, 'Post Carrusel 1: Entorno Visual', 'Carrusel (1080x1080px)', 'Destacar paleta gris perlado y destellos rojos neón.'),
(1, 'Reel 2: Demo de Interfaz', 'Reel 9:16 (1080x1920px)', 'Animación fluida de paneles flotantes y blur glass.');

INSERT INTO content_grid (id, campaign_id, week, day, publish_date, content_type, topic, copy_text, content_format, cm_planning, designer_design, agency_review, cm_publishing, overall_status, progress, observations) VALUES
(1, 1, 1, 'Lunes', '2026-11-02', 'Educativo', 'Uso de colores', 'Aprende a usar paletas de alto impacto.', 'Reel', 'Completado', 'Completado', 'Completado', 'Completado', 'Publicado', 100.00, ''),
(2, 1, 1, 'Miércoles', '2026-11-04', 'Comercial', 'Promo Noviembre', 'Aprovecha el lanzamiento especial de este mes.', 'Carrusel', 'Completado', 'Completado', 'Completado', 'Completado', 'Publicado', 100.00, ''),
(3, 1, 2, 'Lunes', '2026-11-09', 'Interactivo', 'Trivia', '¿Sabías que el diseño influye en la tasa de conversión?', 'Story', 'En Proceso', 'Pendiente', 'Pendiente', 'Pendiente', 'En Producción', 12.50, ''),
(4, 1, 2, 'Viernes', '2026-11-13', 'Institucional', 'Nuestra misión', 'Conoce más sobre cómo impulsamos marcas digitales.', 'Post', 'Completado', 'En Proceso', 'Pendiente', 'Pendiente', 'En Producción', 37.50, '')
ON DUPLICATE KEY UPDATE topic=VALUES(topic);

-- 2. Neon Oasis
INSERT INTO campaigns (id, client_name, month, year, campaign_name, strategy, initials, delivery_date) VALUES
(2, 'Neon Oasis', 'NOVIEMBRE', 2026, 'Neon Oasis', 'Lanzamiento de colección nocturna y experiencias inmersivas.', 'NO', '02/11/2026')
ON DUPLICATE KEY UPDATE client_name=VALUES(client_name);

INSERT INTO responsibles (campaign_id, role, person_name) VALUES
(2, 'community_manager', 'Carlos Rojas'),
(2, 'designer', 'Sofía Pérez'),
(2, 'agency', 'Kinetica');

INSERT INTO brief_references (campaign_id, title, format, content) VALUES
(2, 'Lanzamiento: Colección Nocturna', 'Carrusel de 4 láminas', 'Iluminación vibrante sobre fondo obsidiana oscuro.'),
(2, 'Story Flash: Descuento 24h', 'Story interactiva', 'Tipografía bold, colores altamente contrastados.');

INSERT INTO content_grid (id, campaign_id, week, day, publish_date, content_type, topic, copy_text, content_format, cm_planning, designer_design, agency_review, cm_publishing, overall_status, progress, observations) VALUES
(101, 2, 1, 'Martes', '2026-11-03', 'Lanzamiento', 'Colección Neon', 'Ilumina tu noche con la nueva línea futurista.', 'Reel', 'Completado', 'Completado', 'Completado', 'Completado', 'Publicado', 100.00, ''),
(102, 2, 2, 'Jueves', '2026-11-12', 'Promoción', 'Descuento Flash', 'Solo por 24 horas descuentos en toda la tienda.', 'Story', 'Completado', 'En Proceso', 'Pendiente', 'Pendiente', 'En Producción', 37.50, '')
ON DUPLICATE KEY UPDATE topic=VALUES(topic);

-- 3. Umami Pastelería
INSERT INTO campaigns (id, client_name, month, year, campaign_name, strategy, initials, delivery_date) VALUES
(3, 'Umami Pastelería', 'OCTUBRE', 2026, 'Lanzamiento de identidad: "Hecho a mano para tus momentos" (22 oct – 6 nov 2026)', 'Lanzamiento de identidad: "Hecho a mano para tus momentos" (22 oct – 6 nov 2026)', 'UM', '20/10/2026')
ON DUPLICATE KEY UPDATE client_name=VALUES(client_name);

INSERT INTO responsibles (campaign_id, role, person_name) VALUES
(3, 'community_manager', 'Wilfredo Puertas'),
(3, 'designer', 'Victor Corio'),
(3, 'agency', 'Kinetica');

INSERT INTO brief_references (campaign_id, title, format, content) VALUES
(3, 'Presentación Salvia & Cobre', 'Carrusel de Presentación (5 láminas)', 'Uso de texturas artesanales, tonos cobre y crema suave.'),
(3, 'Detrás de escena: El horneado', 'Reel vertical', 'Fotografía real con grano sutil, calidez visual.');

INSERT INTO content_grid (id, campaign_id, week, day, publish_date, content_type, topic, copy_text, content_format, cm_planning, designer_design, agency_review, cm_publishing, overall_status, progress, observations) VALUES
(201, 3, 1, 'Jueves', '2026-10-22', 'Identidad', 'Presentación Salvia & Cobre', 'Hecho a mano para tus momentos especiales.', 'Carrusel', 'Completado', 'Completado', 'Completado', 'En Proceso', 'En Producción', 87.50, ''),
(202, 3, 1, 'Viernes', '2026-10-23', 'Proceso', 'Detrás de escena', 'El arte de hornear cada mañana con pasión.', 'Reel', 'Completado', 'En Proceso', 'Pendiente', 'Pendiente', 'En Producción', 37.50, ''),
(203, 3, 2, 'Lunes', '2026-10-26', 'Carta', 'Porciones y pedidos', 'Agenda abierta diciembre, reserva tus postres preferidos.', 'Post', 'Pendiente', 'Pendiente', 'Pendiente', 'Pendiente', 'Pendiente', 0.00, '')
ON DUPLICATE KEY UPDATE topic=VALUES(topic);

-- Miembros de equipo iniciales
INSERT INTO team_members (name, role, email) VALUES
('Wilfredo Puertas', 'community_manager', 'wilfredo@kinetica.com'),
('Valentina Almeida', 'community_manager', 'valentina@kinetica.com'),
('Carlos Rojas', 'community_manager', 'carlos@kinetica.com'),
('Victor Corio', 'designer', 'victor@kinetica.com'),
('Sofía Pérez', 'designer', 'sofia@kinetica.com');

-- Usuarios iniciales
INSERT INTO users (name, email, role) VALUES
('Wilfredo Puertas', 'wilfredo@kinetica.com', 'community_manager'),
('Victor Corio', 'victor@kinetica.com', 'designer'),
('Valentina Almeida', 'valentina@kinetica.com', 'community_manager'),
('Carlos Rojas', 'carlos@kinetica.com', 'community_manager'),
('Sofía Pérez', 'sofia@kinetica.com', 'designer'),
('Admin Kinetica', 'admin@kinetica.com', 'admin')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Logs iniciales
INSERT INTO activity_logs (action, description, user_name) VALUES
('Sistema', 'Inicio del sistema Kinetica Dashboard v2.0', 'Admin'),
('Base de Datos', 'Tablas iniciales sincronizadas con éxito', 'Sistema');