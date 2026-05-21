<?php
// Главная страница — редирект на вход или дашборд
require_once __DIR__ . '/config/config.php';   // 1. Сначала константы + session_start()
require_once __DIR__ . '/config/db.php';        // 2. Потом БД
require_once __DIR__ . '/config/functions.php'; // 3. Потом функции


if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard.php');
} else {
    header('Location: ' . SITE_URL . '/auth/login.php');
}
exit;
