<?php
require_once "auth.php";
require_once "connexion.php";
$message = "";
$type_message = "success";
$devises = ['USD', 'CDF'];
// FONCTION DE PROTECTION HTML
function e($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, 'UTF-8');
}
// RECUPERATION DES CATEGORIES
$categories = $pdo->query("
    SELECT id, nom_categorie
    FROM categories
    WHERE deleted_at IS NULL
    ORDER BY nom_categorie ASC
")->fetchAll(PDO::FETCH_ASSOC);
// AJOUT OU MODIFICATION D'UN PRODUIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer'])) {
    $id = (int)($_POST['id_produit'] ?? 0);
    $code = trim($_POST['code_produit'] ?? '');
    $nom = trim($_POST['nom_produit'] ?? '');
    $categorie = (int)($_POST['id_categorie'] ?? 0);
    $unite = trim($_POST['unite'] ?? 'Bouteille');
    $emballage = trim($_POST['type_emballage'] ?? 'Casier');
    $quantite_emballage = (int)($_POST['quantite_par_emballage'] ?? 1);
    $prix_achat = (float)($_POST['prix_achat'] ?? 0);
    $devise_achat = $_POST['devise_achat'] ?? 'USD';
    $prix_vente = (float)($_POST['prix_vente'] ?? 0);
    $devise_vente = $_POST['devise_vente'] ?? 'USD';
    $stock_minimum = (float)($_POST['stock_minimum'] ?? 0);
    // VALIDATION
    if (
        $code === '' ||
        $nom === '' ||
        $categorie <= 0 ||
        $unite === '' ||
        $emballage === '' ||
        $quantite_emballage < 1 ||
        $prix_achat < 0 ||
        $prix_vente < 0 ||
        $stock_minimum < 0 ||
        !in_array($devise_achat, $devises, true) ||
        !in_array($devise_vente, $devises, true)
    ) {
        $message = "Veuillez remplir correctement tous les champs.";
        $type_message = "danger";
    } else {
        // VERIFIER QUE LA CATEGORIE EXISTE
        $verification = $pdo->prepare("
            SELECT id FROM categories
            WHERE id = ?
            AND deleted_at IS NULL
        ");
        $verification->execute([$categorie]);
        if (!$verification->fetch()) {
            $message = "La catégorie sélectionnée n'existe pas.";
            $type_message = "danger";
        } else {
            try {
                if ($id > 0) {
                    // MODIFICATION
                    $sql = $pdo->prepare("
                        UPDATE produits SET
                            code_produit = ?,
                            nom_produit = ?,
                            id_categorie = ?,
                            unite = ?,
                            type_emballage = ?,
                            quantite_par_emballage = ?,
                            prix_achat = ?,
                            devise_achat = ?,
                            prix_vente = ?,
                            devise_vente = ?,
                            stock_minimum = ?
                        WHERE id = ?
                        AND deleted_at IS NULL
                    ");
                    $sql->execute([
                        $code,
                        $nom,
                        $categorie,
                        $unite,
                        $emballage,
                        $quantite_emballage,
                        $prix_achat,
                        $devise_achat,
                        $prix_vente,
                        $devise_vente,
                        $stock_minimum,
                        $id
                    ]);
                    $message = "Produit modifié avec succès.";
                } else {
                    // AJOUT
                    $sql = $pdo->prepare("
                        INSERT INTO produits (
                            code_produit,
                            nom_produit,
                            id_categorie,
                            unite,
                            type_emballage,
                            quantite_par_emballage,
                            prix_achat,
                            devise_achat,
                            prix_vente,
                            devise_vente,
                            quantite_stock,
                            stock_minimum
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
                    ");
                    $sql->execute([
                        $code,
                        $nom,
                        $categorie,
                        $unite,
                        $emballage,
                        $quantite_emballage,
                        $prix_achat,
                        $devise_achat,
                        $prix_vente,
                        $devise_vente,
                        $stock_minimum
                    ]);
                    $message = "Produit ajouté avec succès.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = "Ce code produit existe déjà.";
                } else {
                    $message = "Erreur lors de l'enregistrement : " . $e->getMessage();
                }
                $type_message = "danger";
            }
        }
    }
}
// SUPPRESSION LOGIQUE
if (isset($_GET['supprimer'])) {
    $id = (int)$_GET['supprimer'];
    if ($id > 0) {
        $sql = $pdo->prepare("
            UPDATE produits
            SET deleted_at = NOW()
            WHERE id = ?
            AND deleted_at IS NULL
        ");
        $sql->execute([$id]);
    }
    header("Location: produits.php?supprime=1");
    exit;
}
if (isset($_GET['supprime'])) {
    $message = "Produit envoyé dans la corbeille.";
}
// PRODUIT A MODIFIER
$produit_edit = null;
if (isset($_GET['modifier'])) {
    $id = (int)$_GET['modifier'];
    $sql = $pdo->prepare("
        SELECT *
        FROM produits
        WHERE id = ?
        AND deleted_at IS NULL
    ");
    $sql->execute([$id]);
    $produit_edit = $sql->fetch(PDO::FETCH_ASSOC);
    if (!$produit_edit) {
        $message = "Produit introuvable.";
        $type_message = "danger";
    }
}
// RECHERCHE DES PRODUITS
$recherche = trim($_GET['recherche'] ?? '');
$sql = "
    SELECT
        p.*,
        c.nom_categorie
    FROM produits p
    LEFT JOIN categories c
        ON p.id_categorie = c.id
        AND c.deleted_at IS NULL
    WHERE p.deleted_at IS NULL
";
$params = [];
if ($recherche !== '') {
    $sql .= "
        AND (
            p.nom_produit LIKE ?
            OR p.code_produit LIKE ?
            OR c.nom_categorie LIKE ?
        )
    ";
    $mot = "%$recherche%";
    $params = [$mot, $mot, $mot];
}
$sql .= " ORDER BY p.nom_produit ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
// STATISTIQUES
$total_produits = $pdo->query("
    SELECT COUNT(*)
    FROM produits
    WHERE deleted_at IS NULL
")->fetchColumn();
$total_stock = $pdo->query("
    SELECT COALESCE(SUM(quantite_stock), 0)
    FROM produits
    WHERE deleted_at IS NULL
")->fetchColumn();
$stock_faible = $pdo->query("
    SELECT COUNT(*)
    FROM produits
    WHERE deleted_at IS NULL
    AND quantite_stock <= stock_minimum
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Produits | DINPE HOUSE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
* {
    box-sizing: border-box;
}
body {
    background: #101114;
    color: #f5f5f5;
    font-family: Arial, sans-serif;
    margin: 0;
}
.sidebar {
    width: 245px;
    min-height: 100vh;
    background: #17181d;
    position: fixed;
    top: 0;
    left: 0;
    padding: 25px 15px;
    border-right: 1px solid #292a30;
    z-index: 100;
}
.brand {
    color: #d6b45c;
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 35px;
    text-align: center;
}
.sidebar a {
    display: block;
    padding: 12px 15px;
    color: #bbb;
    text-decoration: none;
    border-radius: 8px;
    margin-bottom: 7px;
    transition: .3s;
}
.sidebar a:hover,
.sidebar a.active {
    background: #d6b45c;
    color: #111;
}
.main {
    margin-left: 245px;
    padding: 30px;
}
.card-box {
    background: #1b1c22;
    border: 1px solid #303139;
    border-radius: 13px;
    padding: 20px;
    margin-bottom: 22px;
}
.stat-number {
    font-size: 27px;
    font-weight: bold;
    color: #d6b45c;
}
.form-control,
.form-select {
    background: #25262d;
    border: 1px solid #3c3d46;
    color: white;
}
.form-control:focus,
.form-select:focus {
    background: #25262d;
    color: white;
    border-color: #d6b45c;
    box-shadow: none;
}
.form-control::placeholder {
    color: #999;
}
.form-select option {
    background: #25262d;
    color: white;
}
.form-label {
    color: #ddd;
}
.table {
    color: #eee;
    vertical-align: middle;
}
.table thead th {
    background: #292a30;
    color: #d6b45c;
    border-color: #3c3d46;
    white-space: nowrap;
}
.table td {
    background: #1b1c22;
    border-color: #303139;
}
.btn-gold {
    background: #d6b45c;
    color: #111;
    border: none;
    font-weight: bold;
}
.btn-gold:hover {
    background: #edcc78;
    color: #111;
}
h2, h4 {
    color: #fff;
}
.alert {
    border-radius: 10px;
}
@media(max-width: 850px) {
    .sidebar {
        position: relative;
        width: 100%;
        min-height: auto;
    }
    .main {
        margin-left: 0;
        padding: 15px;
    }
}
</style>
</head>
<body>
<!-- MENU LATERAL -->
<div class="sidebar">
<div class="brand">
    DINPE HOUSE
</div>
<a href="dashboard.php">
    <i class="bi bi-speedometer2 me-2"></i>
    Tableau de bord
</a>
<a href="produits.php" class="active">
    <i class="bi bi-box-seam me-2"></i>
    Produits
</a>
<a href="approvisionnement.php">
    <i class="bi bi-truck me-2"></i>
    Approvisionnements
</a>
<a href="ventes.php">
    <i class="bi bi-cart-check me-2"></i>
    Ventes
</a>
<a href="depenses.php">
    <i class="bi bi-wallet2 me-2"></i>
    Dépenses
</a>
<a href="entrees.php">
    <i class="bi bi-cash-stack me-2"></i>
    Autres entrées
</a>
<a href="rapports.php">
    <i class="bi bi-bar-chart me-2"></i>
    Rapports
</a>
<a href="historique.php">
    <i class="bi bi-clock-history me-2"></i>
    Historique
</a>
<a href="corbeille.php">
    <i class="bi bi-trash me-2"></i>
    Corbeille
</a>
<a href="parametres.php">
    <i class="bi bi-gear me-2"></i>
    Paramètres
</a>
<a href="logout.php">
    <i class="bi bi-box-arrow-right me-2"></i>
    Déconnexion
</a>
</div>
<!-- CONTENU PRINCIPAL -->
<div class="main">
<div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
    <div>
        <h2>Gestion des produits</h2>
        <p class="text-secondary">
            Enregistrement et suivi des boissons de DINPE HOUSE.
        </p>
    </div>
    <button class="btn btn-gold"
        onclick="document.getElementById('formulaire').scrollIntoView({behavior:'smooth'})">
        <i class="bi bi-plus-circle me-1"></i>
        Nouveau produit
    </button>
</div>
<!-- NOTIFICATIONS -->
<?php if ($message): ?>
    <div class="alert alert-<?= e($type_message) ?> alert-dismissible fade show">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<!-- STATISTIQUES -->
<div class="row">
    <div class="col-md-4">
        <div class="card-box">
            <div class="text-secondary">
                Nombre de produits
            </div>
            <div class="stat-number">
                <?= (int)$total_produits ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-box">
            <div class="text-secondary">
                Stock total en unités
            </div>
            <div class="stat-number">
                <?= number_format((float)$total_stock, 0, ',', ' ') ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-box">
            <div class="text-secondary">
                Produits en stock faible
            </div>
            <div class="stat-number">
                <?= (int)$stock_faible ?>
            </div>
        </div>
    </div>
</div>
<!-- FORMULAIRE PRODUIT -->
<div class="card-box" id="formulaire">
    <h4 class="mb-4">
        <?= $produit_edit ? "Modifier le produit" : "Enregistrer un nouveau produit" ?>
    </h4>
    <form method="POST">
        <input
            type="hidden"
            name="id_produit"
            value="<?= e($produit_edit['id'] ?? '') ?>"
        >
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">
                    Code du produit
                </label>
                <input
                    type="text"
                    name="code_produit"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['code_produit'] ?? '') ?>"
                    placeholder="Ex : BIER001"
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">
                    Nom du produit
                </label>
                <input
                    type="text"
                    name="nom_produit"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['nom_produit'] ?? '') ?>"
                    placeholder="Ex : Sucrée"
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">
                    Catégorie
                </label>
                <select name="id_categorie" class="form-select" required>
                    <option value="">
                        Choisir une catégorie
                    </option>
                    <?php foreach ($categories as $cat): ?>
                        <option
                            value="<?= (int)$cat['id'] ?>"
                            <?= (($produit_edit['id_categorie'] ?? '') == $cat['id']) ? 'selected' : '' ?>
                        >
                            <?= e($cat['nom_categorie']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Unité de vente
                </label>
                <input
                    type="text"
                    name="unite"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['unite'] ?? 'Bouteille') ?>"
                    placeholder="Ex : Bouteille"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Type d'emballage
                </label>
                <input
                    type="text"
                    name="type_emballage"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['type_emballage'] ?? 'Casier') ?>"
                    placeholder="Ex : Casier"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Unités par emballage
                </label>
                <input
                    type="number"
                    min="1"
                    name="quantite_par_emballage"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['quantite_par_emballage'] ?? 20) ?>"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Stock minimum (unités)
                </label>
                <input
                    type="number"
                    min="0"
                    step="1"
                    name="stock_minimum"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['stock_minimum'] ?? 10) ?>"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Prix d'achat
                </label>
                <input
                    type="number"
                    min="0"
                    step="0.01"
                    name="prix_achat"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['prix_achat'] ?? '') ?>"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Devise d'achat
                </label>
                <select name="devise_achat" class="form-select">
                    <option value="USD" <?= (($produit_edit['devise_achat'] ?? 'USD') === 'USD') ? 'selected' : '' ?>>
                        USD ($)
                    </option>
                    <option value="CDF" <?= (($produit_edit['devise_achat'] ?? '') === 'CDF') ? 'selected' : '' ?>>
                        Franc congolais (FC)
                    </option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Prix de vente
                </label>
                <input
                    type="number"
                    min="0"
                    step="0.01"
                    name="prix_vente"
                    class="form-control"
                    required
                    value="<?= e($produit_edit['prix_vente'] ?? '') ?>"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">
                    Devise de vente
                </label>
                <select name="devise_vente" class="form-select">
                    <option value="USD" <?= (($produit_edit['devise_vente'] ?? 'USD') === 'USD') ? 'selected' : '' ?>>
                        USD ($)
                    </option>
                    <option value="CDF" <?= (($produit_edit['devise_vente'] ?? '') === 'CDF') ? 'selected' : '' ?>>
                        Franc congolais (FC)
                    </option>
                </select>
            </div>
            <div class="col-12">
                <button
                    type="submit"
                    name="enregistrer"
                    class="btn btn-gold px-4"
                >
                    <i class="bi bi-save me-1"></i>
                    <?= $produit_edit ? "Enregistrer les modifications" : "Enregistrer le produit" ?>
                </button>
                <?php if ($produit_edit): ?>
                    <a href="produits.php" class="btn btn-secondary">
                        Annuler
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
<!-- LISTE DES PRODUITS -->
<div class="card-box">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <h4>Liste des produits</h4>
        <form method="GET" class="d-flex gap-2">
            <input
                type="text"
                name="recherche"
                class="form-control"
                placeholder="Rechercher un produit..."
                value="<?= e($recherche) ?>"
            >
            <button class="btn btn-gold" type="submit">
                <i class="bi bi-search"></i>
            </button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Produit</th>
                    <th>Catégorie</th>
                    <th>Emballage</th>
                    <th>Unités / emballage</th>
                    <th>Stock</th>
                    <th>Prix achat</th>
                    <th>Prix vente</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($produits) > 0): ?>
                <?php foreach ($produits as $p): ?>
                    <tr>
                        <td><?= e($p['code_produit']) ?></td>
                        <td><?= e($p['nom_produit']) ?></td>
                        <td><?= e($p['nom_categorie'] ?? 'Sans catégorie') ?></td>
                        <td><?= e($p['type_emballage'] ?? 'Non défini') ?></td>
                        <td><?= (int)($p['quantite_par_emballage'] ?? 1) ?></td>
                        <td>
                            <?php if ($p['quantite_stock'] <= $p['stock_minimum']): ?>
                                <span class="badge bg-danger">
                                    <?= number_format((float)$p['quantite_stock'], 0, ',', ' ') ?>
                                    (Faible)
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success">
                                    <?= number_format((float)$p['quantite_stock'], 0, ',', ' ') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= number_format((float)$p['prix_achat'], 2, ',', ' ') ?>
                            <?= e($p['devise_achat']) ?>
                        </td>
                        <td>
                            <?= number_format((float)$p['prix_vente'], 2, ',', ' ') ?>
                            <?= e($p['devise_vente']) ?>
                        </td>
                        <td>
                            <a
                                href="produits.php?modifier=<?= (int)$p['id'] ?>"
                                class="btn btn-sm btn-warning"
                                title="Modifier"
                            >
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a
                                href="produits.php?supprimer=<?= (int)$p['id'] ?>"
                                class="btn btn-sm btn-danger"
                                onclick="return confirm('Envoyer ce produit dans la corbeille ?')"
                                title="Supprimer"
                            >
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center text-secondary">
                        Aucun produit enregistré.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Confirmation avant suppression
document.querySelectorAll('.btn-danger').forEach(function(bouton) {
    bouton.addEventListener('click', function(event) {
        if (!confirm('Confirmer la suppression de ce produit ?')) {
            event.preventDefault();
        }
    });
});
</script>
</body>
</html>