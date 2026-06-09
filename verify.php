<?php
// verify.php – weryfikacja email po kliknięciu linku z maila
// GOTOWY DO UŻYCIA – wystarczy odkomentować sendVerificationEmail()
// w AuthController i zmienić status rejestracji na 'pending'
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/controllers/AuthController.php';

$controller = new AuthController(db());
$controller->verifyEmail();
