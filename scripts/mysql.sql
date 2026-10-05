-- Base de datos y usuario MySQL para Blush ERP (incidencia I-01 del README).
-- Uso:  sudo mysql < scripts/mysql.sql
-- La clave debe coincidir con api/config.php (o con la variable de entorno ERP_MYSQL_CLAVE).
-- Es una clave para el entorno local de clase: en un servidor real, cámbiala.

CREATE DATABASE IF NOT EXISTS blush_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'blush'@'localhost' IDENTIFIED BY 'blush_local_2026';
-- Solo permisos sobre su propia base de datos, nunca sobre todo el servidor
GRANT ALL PRIVILEGES ON blush_erp.* TO 'blush'@'localhost';
FLUSH PRIVILEGES;

SELECT 'Base de datos blush_erp y usuario blush listos' AS resultado;
