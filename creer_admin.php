<?php

require_once "connexion.php";

$message = "";

try {

    $verifier = $pdo->query("SELECT COUNT(*) FROM utilisateurs");
    $nombre = $verifier->fetchColumn();

    if ($nombre == 0) {

        $nom = "Administrateur";
        $username = "admin";
        $password = password_hash("Admin@123", PASSWORD_DEFAULT);

        $sql = "INSERT INTO utilisateurs
                (nom, username, password, role)
                VALUES (?, ?, ?, 'admin')";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            $nom,
            $username,
            $password
        ]);

        $message = "Administrateur créé avec succès.";

    } else {

        $message = "Un compte utilisateur existe déjà.";

    }

} catch(PDOException $e) {

    $message = "Erreur : " . $e->getMessage();

}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Création administrateur</title>
</head>

<body>

<h2><?= htmlspecialchars($message) ?></h2>

<p>Identifiant : admin</p>
<p>Mot de passe initial : Admin@123</p>

<p>Supprime maintenant le fichier creer_admin.php pour sécuriser ton application.</p>

<a href="login.php">Se connecter</a>

</body>
</html>