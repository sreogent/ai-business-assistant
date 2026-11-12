<?php
declare(strict_types=1);

/**
 * Миграция: Абсолютная защита прав Верховного Администратора (Матвей Ибадлаев)
 * на уровне ядра базы данных MySQL / MariaDB (триггеры).
 */

require_once dirname(__DIR__) . '/includes/Database.php';

$pdo = Database::getConnection();

echo "Создание триггеров защиты Верховного Администратора...\n";

$pdo->exec('DROP TRIGGER IF EXISTS trg_protect_supreme_admin_update');
$pdo->exec('DROP TRIGGER IF EXISTS trg_protect_supreme_admin_delete');

$pdo->exec("
CREATE TRIGGER trg_protect_supreme_admin_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF (OLD.id = 3 OR OLD.email = 'matvejibadlaev@gmail.com') THEN
        IF NEW.role_id != OLD.role_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Права Верховного Администратора (Матвей Ибадлаев) защищены на уровне ядра СУБД!';
        END IF;
        IF NEW.status != 'active' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Верховный Администратор не может быть заблокирован!';
        END IF;
        IF NEW.email != OLD.email THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email Верховного Администратора защищен от изменения!';
        END IF;
    END IF;
END;
");

$pdo->exec("
CREATE TRIGGER trg_protect_supreme_admin_delete
BEFORE DELETE ON users
FOR EACH ROW
BEGIN
    IF (OLD.id = 3 OR OLD.email = 'matvejibadlaev@gmail.com') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Аккаунт Верховного Администратора защищен от удаления!';
    END IF;
END;
");

echo "Триггеры СУБД успешно установлены!\n";
