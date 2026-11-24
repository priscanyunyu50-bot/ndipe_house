<?php

require_once "connexion.php";

$username = "admin";
$password = password_hash("Admin@123", PASSWORD_DEFAULT);

try {

    $sql = "SELECT id FROM utilisateurs WHERE username = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);

    $admin = $stmt->fetch();

    if ($admin) {

        $sql = "UPDATE utilisateurs
                SET password = ?, statut = 'Actif'
                WHERE username = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$password, $username]);

        echo "Mot de passe administrateur réinitialisé avec succès !";

    } else {

        $sql = "INSERT INTO utilisateurs
                (nom, username, password, role, statut)
                VALUES (?, ?, ?, 'admin', 'Actif')";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            "Administrateur",
            $username,
            $password
        ]);

        echo "Compte administrateur créé avec succès !";

    }

} catch (PDOException $e) {

    echo "Erreur : " . htmlspecialchars($e->getMessage());

}

?>