<?php

declare(strict_types=1);
?>
<section class="card border-0 shadow-sm auth-card" aria-labelledby="login-heading">
    <div class="card-body p-4 p-md-5">
        <p class="text-uppercase fw-semibold text-primary mb-2">Acceso seguro</p>
        <h1 class="h2 mb-3" id="login-heading">Iniciar sesión</h1>
        <p class="text-body-secondary">Ingresa tus credenciales para continuar.</p>

        <form method="post" action="/login">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
                <label class="form-label" for="username">Usuario</label>
                <input class="form-control" id="username" name="username" type="text" autocomplete="username" required autofocus>
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Contraseña</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <button class="btn btn-primary w-100" type="submit">Iniciar sesión</button>
        </form>

        <p class="text-center mt-4 mb-0"><a href="/forgot-password">¿Olvidaste tu contraseña?</a></p>
    </div>
</section>
