<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$assetVersion = '20260420-2';

$error = null;
$bootstrapMode = !auth()->anyUsersExist();

if (currentUser() !== null) {
    redirect('./index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($bootstrapMode) {
            $username = requestString('username', '');
            $password = requestString('password', '');
            $firstName = requestString('first_name', '');
            $lastName = requestString('last_name', '');
            $email = requestString('email', '');

            if ($username === '' || $password === '' || $firstName === '' || $lastName === '') {
                throw new RuntimeException('Completa usuario, contraseña, nombre y apellido.');
            }

            auth()->bootstrapAdmin($username, $password, $firstName, $lastName, $email ?? '');
            auth()->login($username, $password, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
            redirect('./index.php');
        }

        $ok = auth()->login(
            requestString('username', '') ?? '',
            requestString('password', '') ?? '',
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        if ($ok) {
            redirect('./index.php');
        }

        $error = 'Usuario o contraseña inválidos.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(config('app_name', 'Maritano')) ?> - Acceso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/styles.css?v=<?= e($assetVersion) ?>" rel="stylesheet">
</head>
<body class="login-page">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><?= $bootstrapMode ? 'Crear administrador inicial' : 'Ingreso al sistema' ?></h1>
                    <div class="text-secondary small mb-4"><?= e(config('app_name', 'Maritano')) ?></div>

                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php if ($bootstrapMode): ?>
                            <div class="mb-3"><label class="form-label">Nombre</label><input class="form-control" name="first_name" required></div>
                            <div class="mb-3"><label class="form-label">Apellido</label><input class="form-control" name="last_name" required></div>
                            <div class="mb-3"><label class="form-label">Correo</label><input class="form-control" name="email"></div>
                        <?php endif; ?>
                        <div class="mb-3"><label class="form-label">Usuario</label><input class="form-control" name="username" required></div>
                        <div class="mb-3"><label class="form-label">Contraseña</label><input type="password" class="form-control" name="password" required></div>
                        <button class="btn btn-primary w-100" type="submit"><?= $bootstrapMode ? 'Crear administrador' : 'Ingresar' ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
