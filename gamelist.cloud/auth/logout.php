<?php
session_start();
require_once __DIR__ . '/../auth.php';

// [GUARD] Only accept POST; redirect GET requests to the main page.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

verifyAuthCsrf();
logoutUser();
header('Location: ../index.php');
exit;
