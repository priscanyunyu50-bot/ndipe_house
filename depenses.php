<?php
require_once 'auth.php';
require_once 'connexion.php';
$message = '';
$erreur = '';
/* DÉTECTER AUTOMATIQUEMENT LA COLONNE DU NOM DE CATÉGORIE */
try {
    $colonnes = $pdo->query("SHOW COLUMNS FROM categories_depenses")->fetchAll(PDO::FETCH_COLUMN);
    $colonneNom = null;
    $nomsPossibles = [
        'nom_categorie',
        'nom',
        'categorie',
        'libelle',
        'designation'
    ];
    foreach ($nomsPossibles as $nom) {
        if (in_array($nom, $colonnes, true)) {
            $colonneNom = $nom;
            break;
        }
    }
    if ($colonneNom === null) {
        throw new Exception("Impossible de trouver la colonne contenant le nom des catégories dans categories_depenses.");
    }
} catch (Throwable $e) {
    die("Erreur de configuration des catégories : " . htmlspecialchars($e->getMessage()));
}
/* ENREGISTRER UNE DÉPENSE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer'])) {
    try {
        $categorie = (int)($_POST['id_categorie'] ?? 0);
        $libelle = trim($_POST['libelle'] ?? '');
        $montant = (float)($_POST['montant'] ?? 0);
        $devise = $_POST['devise'] ?? 'USD';
        $date = $_POST['date_depense'] ?? date('Y-m-d');
        $observation = trim($_POST['observation'] ?? '');
        if ($categorie <= 0 || $libelle === '' || $montant <= 0) {
            throw new Exception("Veuillez remplir correctement les champs obligatoires.");
        }
        if (!in_array($devise, ['USD', 'CDF'], true)) {
            throw new Exception("Devise invalide.");
        }
        $verification = $pdo->prepare("SELECT id FROM categories_depenses WHERE id = ?");
        $verification->execute([$categorie]);
        if (!$verification->fetch()) {
            throw new Exception("Catégorie introuvable.");
        }
        $stmt = $pdo->prepare("
            INSERT INTO depenses
            (id_categorie, libelle, montant, devise, date_depense, observation)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $categorie,
            $libelle,
            $montant,
            $devise,
            $date,
            $observation
        ]);
        $message = "Dépense enregistrée avec succès !";
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}
/* MODIFIER UNE DÉPENSE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier'])) {
    try {
        $id = (int)($_POST['id_depense'] ?? 0);
        $categorie = (int)($_POST['id_categorie'] ?? 0);
        $libelle = trim($_POST['libelle'] ?? '');
        $montant = (float)($_POST['montant'] ?? 0);
        $devise = $_POST['devise'] ?? 'USD';
        $date = $_POST['date_depense'] ?? date('Y-m-d');
        $observation = trim($_POST['observation'] ?? '');
        if ($id <= 0 || $categorie <= 0 || $libelle === '' || $montant <= 0) {
            throw new Exception("Informations de modification invalides.");
        }
        $stmt = $pdo->prepare("
            UPDATE depenses
            SET id_categorie = ?, libelle = ?, montant = ?,
                devise = ?, date_depense = ?, observation = ?
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([
            $categorie,
            $libelle,
            $montant,
            $devise,
            $date,
            $observation,
            $id
        ]);
        $message = "Dépense modifiée avec succès !";
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}
/* SUPPRIMER VERS LA CORBEILLE */
if (isset($_GET['supprimer'])) {
    try {
        $id = (int)$_GET['supprimer'];
        $stmt = $pdo->prepare("
            UPDATE depenses
            SET deleted_at = NOW()
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $message = "Dépense déplacée dans la corbeille.";
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}
/* CHARGER LES CATÉGORIES */
$categories = $pdo->query("
    SELECT id, `$colonneNom` AS nom_affiche
    FROM categories_depenses
    ORDER BY `$colonneNom` ASC
")->fetchAll(PDO::FETCH_ASSOC);
/* FILTRES */
$filtreDevise = $_GET['devise'] ?? '';
$recherche = trim($_GET['recherche'] ?? '');
$sql = "
    SELECT d.*, c.`$colonneNom` AS nom_categorie
    FROM depenses d
    LEFT JOIN categories_depenses c ON c.id = d.id_categorie
    WHERE d.deleted_at IS NULL
";
$params = [];
if (in_array($filtreDevise, ['USD', 'CDF'], true)) {
    $sql .= " AND d.devise = ?";
    $params[] = $filtreDevise;
}
if ($recherche !== '') {
    $sql .= " AND (d.libelle LIKE ? OR c.`$colonneNom` LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}
$sql .= " ORDER BY d.date_depense DESC, d.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$depenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
/* TOTAUX DU JOUR */
$totalUSD = 0;
$totalCDF = 0;
foreach ($depenses as $d) {
    if ($d['date_depense'] === date('Y-m-d')) {
        if ($d['devise'] === 'USD') {
            $totalUSD += (float)$d['montant'];
        } elseif ($d['devise'] === 'CDF') {
            $totalCDF += (float)$d['montant'];
        }
    }
}
/* CHARGER UNE DÉPENSE À MODIFIER */
$depenseModifier = null;
if (isset($_GET['modifier'])) {
    $id = (int)$_GET['modifier'];
    $stmt = $pdo->prepare("
        SELECT * FROM depenses
        WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$id]);
    $depenseModifier = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dépenses | DINPE HOUSE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --or:#d4af37;
    --fond:#101010;
    --carte:#1b1b1b;
}
body {
    background:var(--fond);
    color:#f5f5f5;
    font-family:Arial,sans-serif;
}
.sidebar {
    width:245px;
    min-height:100vh;
    background:#080808;
    position:fixed;
    padding:25px 15px;
    border-right:1px solid #292929;
}
.logo {
    color:var(--or);
    font-size:23px;
    font-weight:bold;
    text-align:center;
    margin-bottom:35px;
}
.sidebar a {
    display:block;
    padding:12px 15px;
    margin-bottom:8px;
    color:#ccc;
    text-decoration:none;
    border-radius:8px;
}
.sidebar a:hover,.sidebar a.active {
    background:var(--or);
    color:#111;
}
.main {
    margin-left:245px;
    padding:30px;
}
.card-custom {
    background:var(--carte);
    border:1px solid #333;
    border-radius:14px;
    padding:22px;
    margin-bottom:22px;
}
h2,h4 {color:var(--or);}
.form-control,.form-select {
    background:#101010;
    color:white;
    border:1px solid #444;
}
.form-control:focus,.form-select:focus {
    background:#101010;
    color:white;
    border-color:var(--or);
    box-shadow:none;
}
.form-select option {background:#111;color:white;}
.btn-gold {
    background:var(--or);
    color:#111;
    border:none;
    font-weight:bold;
}
.btn-gold:hover {background:#f0cf58;color:#111;}
.table {color:white;vertical-align:middle;}
.table td,.table th {border-color:#383838;}
.total-box {
    color:var(--or);
    font-size:25px;
    font-weight:bold;
}
@media(max-width:800px) {
    .sidebar {position:relative;width:100%;min-height:auto;}
    .main {margin-left:0;padding:15px;}
}
</style>
</head>
<body>
<div class="sidebar">
    <div class="logo">DINPE HOUSE</div>
    <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Tableau de bord</a>
    <a href="produits.php"><i class="bi bi-cup-straw me-2"></i> Produits</a>
    <a href="approvisionnement.php"><i class="bi bi-box-seam me-2"></i> Approvisionnements</a>
    <a href="ventes.php"><i class="bi bi-cart-check me-2"></i> Ventes</a>
    <a href="depenses.php" class="active"><i class="bi bi-wallet2 me-2"></i> Dépenses</a>
    <a href="entrees.php"><i class="bi bi-cash-stack me-2"></i> Autres entrées</a>
    <a href="rapports.php"><i class="bi bi-bar-chart me-2"></i> Rapports</a>
    <a href="historique.php"><i class="bi bi-clock-history me-2"></i> Historique</a>
    <a href="corbeille.php"><i class="bi bi-trash me-2"></i> Corbeille</a>
    <a href="parametres.php"><i class="bi bi-gear me-2"></i> Paramètres</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Déconnexion</a>
</div>
<div class="main">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-wallet2"></i> Gestion des dépenses</h2>
            <p class="text-secondary mb-0">Suivi des sorties d'argent de DINPE HOUSE</p>
        </div>
        <span class="text-warning"><?= date('d/m/Y') ?></span>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>
    <div class="row">
        <div class="col-md-6">
            <div class="card-custom">
                <small class="text-secondary">Dépenses du jour en USD</small>
                <div class="total-box"><?= number_format($totalUSD, 2) ?> $</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-custom">
                <small class="text-secondary">Dépenses du jour en FC</small>
                <div class="total-box"><?= number_format($totalCDF, 2) ?> FC</div>
            </div>
        </div>
    </div>
    <div class="card-custom">
        <h4 class="mb-4">
            <?= $depenseModifier ? 'Modifier une dépense' : 'Nouvelle dépense' ?>
        </h4>
        <form method="POST">
            <?php if ($depenseModifier): ?>
                <input type="hidden" name="id_depense" value="<?= (int)$depenseModifier['id'] ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Catégorie</label>
                    <select name="id_categorie" class="form-select" required>
                        <option value="">Choisir une catégorie</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"
                                <?= $depenseModifier && $depenseModifier['id_categorie'] == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nom_affiche']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Libellé de la dépense</label>
                    <input type="text" name="libelle" class="form-control"
                           placeholder="Exemple : Transport des boissons"
                           value="<?= htmlspecialchars($depenseModifier['libelle'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Montant</label>
                    <input type="number" name="montant" class="form-control"
                           min="0.01" step="0.01"
                           value="<?= htmlspecialchars($depenseModifier['montant'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Devise</label>
                    <select name="devise" class="form-select" required>
                        <option value="USD" <?= ($depenseModifier['devise'] ?? '') === 'USD' ? 'selected' : '' ?>>Dollar ($)</option>
                        <option value="CDF" <?= ($depenseModifier['devise'] ?? '') === 'CDF' ? 'selected' : '' ?>>Franc congolais (FC)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date de dépense</label>
                    <input type="date" name="date_depense" class="form-control"
                           value="<?= htmlspecialchars($depenseModifier['date_depense'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Observation</label>
                    <textarea name="observation" class="form-control" rows="2"><?= htmlspecialchars($depenseModifier['observation'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit"
                            name="<?= $depenseModifier ? 'modifier' : 'enregistrer' ?>"
                            class="btn btn-gold">
                        <i class="bi bi-check-circle"></i>
                        <?= $depenseModifier ? 'Enregistrer les modifications' : 'Enregistrer la dépense' ?>
                    </button>
                    <?php if ($depenseModifier): ?>
                        <a href="depenses.php" class="btn btn-outline-light">Annuler</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <div class="card-custom">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h4 class="mb-0">Historique des dépenses</h4>
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="recherche" class="form-control"
                       placeholder="Rechercher..."
                       value="<?= htmlspecialchars($recherche) ?>">
                <select name="devise" class="form-select">
                    <option value="">Toutes les devises</option>
                    <option value="USD" <?= $filtreDevise === 'USD' ? 'selected' : '' ?>>USD</option>
                    <option value="CDF" <?= $filtreDevise === 'CDF' ? 'selected' : '' ?>>CDF</option>
                </select>
                <button class="btn btn-gold"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Catégorie</th>
                        <th>Libellé</th>
                        <th>Montant</th>
                        <th>Devise</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($depenses)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-secondary">
                            Aucune dépense enregistrée.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($depenses as $d): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($d['date_depense'])) ?></td>
                        <td><?= htmlspecialchars($d['nom_categorie'] ?? 'Sans catégorie') ?></td>
                        <td><?= htmlspecialchars($d['libelle']) ?></td>
                        <td class="text-warning fw-bold"><?= number_format((float)$d['montant'], 2) ?></td>
                        <td><?= htmlspecialchars($d['devise']) ?></td>
                        <td>
                            <a href="depenses.php?modifier=<?= (int)$d['id'] ?>"
                               class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="depenses.php?supprimer=<?= (int)$d['id'] ?>"
                               class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('Déplacer cette dépense dans la corbeille ?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>