<?php

session_start();

require_once "connexion.php";

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

$erreur = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $sql = "SELECT * FROM utilisateurs
            WHERE username = ?
            AND statut = 'Actif'
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);

    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {

        session_regenerate_id(true);

        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_nom'] = $admin['nom'];
        $_SESSION['role'] = $admin['role'];

        header("Location: dashboard.php");
        exit();

    } else {

        $erreur = "Identifiant ou mot de passe incorrect.";

    }

}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Connexion | DINPE HOUSE</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: Arial, sans-serif;

    background:
    linear-gradient(rgba(0,0,0,.82), rgba(0,0,0,.88)),
    url('https://images.unsplash.com/photo-1517248135467-4c7edcad34c4');

    background-size: cover;
    background-position: center;
}

.login-box {
    width: 390px;
    max-width: 92%;
    padding: 40px 30px;
    border-radius: 18px;

    background: rgba(20,20,20,.90);

    border: 1px solid rgba(212,175,55,.4);

    box-shadow: 0 15px 45px rgba(0,0,0,.6);

    color: white;
}

.logo {
    text-align: center;
    font-size: 29px;
    font-weight: bold;
    color: #d4af37;
    letter-spacing: 2px;
}

.subtitle {
    text-align: center;
    color: #bbb;
    margin-bottom: 30px;
}

.form-control {
    background: #292929;
    border: 1px solid #444;
    color: white;
    padding: 12px;
}

.form-control:focus {
    background: #292929;
    color: white;
    border-color: #d4af37;
    box-shadow: none;
}

label {
    margin-bottom: 8px;
    color: #ddd;
}

.btn-login {
    width: 100%;
    background: #d4af37;
    border: none;
    padding: 12px;
    font-weight: bold;
    color: #111;
    border-radius: 8px;
}

.btn-login:hover {
    background: #f0cf64;
}

.footer {
    text-align: center;
    color: #888;
    margin-top: 25px;
    font-size: 12px;
}

</style>

</head>

<body>

<div class="login-box">

    <div class="logo">DINPE HOUSE</div>

    <div class="subtitle">
        Système de gestion de mini-bar
    </div>

    <?php if ($erreur): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($erreur) ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <div class="mb-3">

            <label>Nom d'utilisateur</label>

            <input
                type="text"
                name="username"
                class="form-control"
                placeholder="Votre identifiant"
                required
                autocomplete="username"
            >

        </div>

        <div class="mb-4">

            <label>Mot de passe</label>

            <input
                type="password"
                name="password"
                class="form-control"
                placeholder="Votre mot de passe"
                required
                autocomplete="current-password"
            >

        </div>

        <button type="submit" class="btn-login">
            SE CONNECTER
        </button>

    </form>

    <div class="footer">
        © <?= date('Y') ?> DINPE HOUSE - Tous droits réservés
    </div>

</div>

</body>
</html>
