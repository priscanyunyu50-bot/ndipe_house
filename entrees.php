<?php
require_once 'auth.php';
require_once 'connexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$erreur = '';
$succes = '';

/* Détection des colonnes disponibles */
try {
    $colonnes = $pdo->query("SHOW COLUMNS FROM entrees_financieres")->fetchAll(PDO::FETCH_COLUMN);

    function trouverColonne($colonnes, $possibles) {
        foreach ($possibles as $colonne) {
            if (in_array($colonne, $colonnes, true)) {
                return $colonne;
            }
        }
        return null;
    }

    $colId = trouverColonne($colonnes, ['id']);
    $colLibelle = trouverColonne($colonnes, ['libelle', 'description', 'motif', 'nom_entree', 'designation']);
    $colMontant = trouverColonne($colonnes, ['montant', 'montant_entree', 'somme']);
    $colDevise = trouverColonne($colonnes, ['devise', 'monnaie']);
    $colDate = trouverColonne($colonnes, ['date_entree', 'date_operation', 'date_enregistrement', 'date']);
    $colObservation = trouverColonne($colonnes, ['observation', 'commentaire', 'details']);
    $colDeleted = trouverColonne($colonnes, ['deleted_at']);

    if (!$colId || !$colLibelle || !$colMontant || !$colDevise || !$colDate) {
        throw new Exception("Certaines colonnes indispensables sont introuvables dans la table entrees_financieres.");
    }

} catch (Throwable $e) {
    die("<div style='font-family:Arial;padding:25px;color:#b00020'>
        <h3>Erreur de structure de la base de données</h3>
        ".htmlspecialchars($e->getMessage())."
        <p>Vérifie la structure de la table entrees_financieres dans phpMyAdmin.</p>
    </div>");
}

/* Fonctions */
function h($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, 'UTF-8');
}

function montantFormat($montant) {
    return number_format((float)$montant, 2, '.', ' ');
}

/* Enregistrement */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'enregistrer' || $action === 'modifier') {
            $id = (int)($_POST['id'] ?? 0);
            $libelle = trim($_POST['libelle'] ?? '');
            $montant = (float)($_POST['montant'] ?? 0);
            $devise = $_POST['devise'] ?? 'USD';
            $date = $_POST['date_entree'] ?? date('Y-m-d');
            $observation = trim($_POST['observation'] ?? '');

            if ($libelle === '' || $montant <= 0) {
                throw new Exception("Veuillez remplir correctement le libellé et le montant.");
            }

            if (!in_array($devise, ['USD', 'CDF'], true)) {
                throw new Exception("Devise invalide.");
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception("Date invalide.");
            }

            $champs = [
                $colLibelle => $libelle,
                $colMontant => $montant,
                $colDevise => $devise,
                $colDate => $date
            ];

            if ($colObservation) {
                $champs[$colObservation] = $observation;
            }

            if ($action === 'enregistrer') {
                $noms = array_keys($champs);
                $placeholders = array_map(fn($c) => ':' . $c, $noms);

                $sql = "INSERT INTO entrees_financieres (`" .
                    implode('`,`', $noms) .
                    "`) VALUES (" . implode(',', $placeholders) . ")";

                $stmt = $pdo->prepare($sql);

                foreach ($champs as $nom => $valeur) {
                    $stmt->bindValue(':' . $nom, $valeur);
                }

                $stmt->execute();
                $succes = "Entrée financière enregistrée avec succès.";
            } else {
                if ($id <= 0) {
                    throw new Exception("Identifiant invalide.");
                }

                $sets = [];
                foreach ($champs as $nom => $valeur) {
                    $sets[] = "`$nom` = :$nom";
                }

                $sql = "UPDATE entrees_financieres SET " .
                    implode(', ', $sets) .
                    " WHERE `$colId` = :id";

                if ($colDeleted) {
                    $sql .= " AND `$colDeleted` IS NULL";
                }

                $stmt = $pdo->prepare($sql);

                foreach ($champs as $nom => $valeur) {
                    $stmt->bindValue(':' . $nom, $valeur);
                }

                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();

                $succes = "Entrée financière modifiée avec succès.";
            }
        }

        /* Suppression */
        if ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception("Identifiant invalide.");
            }

            if ($colDeleted) {
                $sql = "UPDATE entrees_financieres SET `$colDeleted` = NOW()
                        WHERE `$colId` = :id AND `$colDeleted` IS NULL";
            } else {
                $sql = "DELETE FROM entrees_financieres WHERE `$colId` = :id";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);

            $succes = "Entrée supprimée avec succès.";
        }

    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}

/* Filtres */
$recherche = trim($_GET['recherche'] ?? '');
$filtreDevise = $_GET['devise'] ?? '';
$dateDebut = $_GET['date_debut'] ?? '';
$dateFin = $_GET['date_fin'] ?? '';

$where = [];
$params = [];

if ($colDeleted) {
    $where[] = "`$colDeleted` IS NULL";
}

if ($recherche !== '') {
    $where[] = "`$colLibelle` LIKE :recherche";
    $params[':recherche'] = "%$recherche%";
}

if (in_array($filtreDevise, ['USD', 'CDF'], true)) {
    $where[] = "`$colDevise` = :devise";
    $params[':devise'] = $filtreDevise;
}

if ($dateDebut !== '') {
    $where[] = "`$colDate` >= :date_debut";
    $params[':date_debut'] = $dateDebut;
}

if ($dateFin !== '') {
    $where[] = "`$colDate` <= :date_fin";
    $params[':date_fin'] = $dateFin;
}

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Liste */
$sql = "SELECT * FROM entrees_financieres $sqlWhere
        ORDER BY `$colDate` DESC, `$colId` DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entrees = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Totaux */
$totalUSD = 0;
$totalCDF = 0;

foreach ($entrees as $entree) {
    if ($entree[$colDevise] === 'USD') {
        $totalUSD += (float)$entree[$colMontant];
    } elseif ($entree[$colDevise] === 'CDF') {
        $totalCDF += (float)$entree[$colMontant];
    }
}

$aujourdhui = date('Y-m-d');

$edition = null;
if (isset($_GET['modifier'])) {
    $idEdition = (int)$_GET['modifier'];

    $stmt = $pdo->prepare(
        "SELECT * FROM entrees_financieres WHERE `$colId` = ? " .
        ($colDeleted ? "AND `$colDeleted` IS NULL" : "")
    );
    $stmt->execute([$idEdition]);
    $edition = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Autres entrées - DINPE HOUSE</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root {
    --fond:#101114;
    --sidebar:#17181d;
    --carte:#1b1c22;
    --or:#d6b45c;
    --texte:#f2f2f2;
    --gris:#a6a6a6;
}

* { box-sizing:border-box; }

body {
    margin:0;
    background:var(--fond);
    color:var(--texte);
    font-family:Arial,sans-serif;
}

.sidebar {
    width:250px;
    height:100vh;
    position:fixed;
    top:0;
    left:0;
    background:var(--sidebar);
    padding:25px 15px;
    overflow-y:auto;
    border-right:1px solid #292a30;
}

.brand {
    color:var(--or);
    font-size:23px;
    font-weight:bold;
    text-align:center;
    margin-bottom:35px;
}

.sidebar a {
    display:flex;
    align-items:center;
    gap:12px;
    color:#ccc;
    text-decoration:none;
    padding:12px 14px;
    border-radius:9px;
    margin-bottom:7px;
    transition:.2s;
}

.sidebar a:hover,
.sidebar a.active {
    background:#302a1d;
    color:var(--or);
}

.main {
    margin-left:250px;
    padding:30px;
}

.page-title {
    color:var(--or);
    font-size:27px;
    font-weight:bold;
}

.subtitle {
    color:var(--gris);
    margin-bottom:25px;
}

.card-custom {
    background:var(--carte);
    border:1px solid #2b2c32;
    border-radius:14px;
    padding:22px;
    height:100%;
}

.stat-title {
    color:var(--gris);
    font-size:14px;
}

.stat-value {
    color:var(--or);
    font-size:25px;
    font-weight:bold;
    margin-top:8px;
}

.form-label {
    color:#ddd;
}

.form-control,
.form-select {
    background:#111216;
    border:1px solid #393a40;
    color:white;
    padding:11px;
}

.form-control:focus,
.form-select:focus {
    background:#111216;
    color:white;
    border-color:var(--or);
    box-shadow:0 0 0 .2rem #d6b45c22;
}

.form-control::placeholder {
    color:#777;
}

.form-select option {
    background:#17181d;
    color:white;
}

.btn-gold {
    background:var(--or);
    color:#111;
    border:none;
    font-weight:bold;
    padding:11px 18px;
}

.btn-gold:hover {
    background:#ebca72;
    color:#111;
}

.table {
    color:#eee;
    vertical-align:middle;
}

.table thead th {
    color:var(--or);
    background:#25262c;
    border-color:#383940;
    white-space:nowrap;
}

.table td {
    background:#1b1c22;
    border-color:#33343a;
}

.table tbody tr:hover td {
    background:#24252b;
}

.badge-usd {
    background:#245c42;
}

.badge-cdf {
    background:#394c79;
}

.btn-action {
    border:1px solid #45464d;
    color:#ddd;
    background:transparent;
    padding:5px 9px;
    border-radius:6px;
}

.btn-action:hover {
    color:var(--or);
    border-color:var(--or);
}

.alert {
    border:none;
    border-radius:9px;
}

.empty {
    color:#999;
    text-align:center;
    padding:30px;
}

@media(max-width:850px) {
    .sidebar {
        width:100%;
        height:auto;
        position:relative;
    }

    .main {
        margin-left:0;
        padding:18px;
    }

    .sidebar nav {
        display:grid;
        grid-template-columns:repeat(2,1fr);
    }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">
        <i class="bi bi-cup-hot-fill"></i> DINPE HOUSE
    </div>

    <nav>
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Tableau de bord</a>
        <a href="produits.php"><i class="bi bi-box-seam"></i> Produits</a>
        <a href="approvisionnement.php"><i class="bi bi-truck"></i> Approvisionnements</a>
        <a href="ventes.php"><i class="bi bi-cart3"></i> Ventes</a>
        <a href="depenses.php"><i class="bi bi-wallet2"></i> Dépenses</a>
        <a href="entrees.php" class="active"><i class="bi bi-cash-stack"></i> Autres entrées</a>
        <a href="rapports.php"><i class="bi bi-bar-chart"></i> Rapports</a>
        <a href="historique.php"><i class="bi bi-clock-history"></i> Historique</a>
        <a href="corbeille.php"><i class="bi bi-trash"></i> Corbeille</a>
        <a href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
    </nav>
</aside>

<main class="main">

    <div class="mb-4">
        <div class="page-title">
            <i class="bi bi-cash-stack"></i> Autres entrées financières
        </div>
        <div class="subtitle">
            Gestion des revenus autres que les ventes du mini-bar.
        </div>
    </div>

    <?php if ($succes): ?>
        <div class="alert alert-success"><?= h($succes) ?></div>
    <?php endif; ?>

    <?php if ($erreur): ?>
        <div class="alert alert-danger"><?= h($erreur) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card-custom">
                <div class="stat-title">
                    <i class="bi bi-currency-dollar"></i> Total des entrées en USD
                </div>
                <div class="stat-value">$ <?= montantFormat($totalUSD) ?></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card-custom">
                <div class="stat-title">
                    <i class="bi bi-cash"></i> Total des entrées en CDF
                </div>
                <div class="stat-value"><?= montantFormat($totalCDF) ?> FC</div>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <div class="col-lg-4">
            <div class="card-custom">

                <h5 class="mb-4" style="color:var(--or)">
                    <i class="bi bi-plus-circle"></i>
                    <?= $edition ? 'Modifier une entrée' : 'Nouvelle entrée' ?>
                </h5>

                <form method="POST">

                    <input type="hidden" name="action"
                           value="<?= $edition ? 'modifier' : 'enregistrer' ?>">

                    <?php if ($edition): ?>
                        <input type="hidden" name="id"
                               value="<?= (int)$edition[$colId] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Libellé de l'entrée *</label>
                        <input type="text" name="libelle" class="form-control"
                               placeholder="Ex : Apport de fonds, remboursement..."
                               value="<?= h($edition[$colLibelle] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Montant *</label>
                        <input type="number" name="montant" class="form-control"
                               min="0.01" step="0.01" placeholder="0.00"
                               value="<?= h($edition[$colMontant] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Devise *</label>
                        <select name="devise" class="form-select" required>
                            <option value="USD"
                                <?= (($edition[$colDevise] ?? 'USD') === 'USD') ? 'selected' : '' ?>>
                                USD - Dollar américain
                            </option>
                            <option value="CDF"
                                <?= (($edition[$colDevise] ?? '') === 'CDF') ? 'selected' : '' ?>>
                                CDF - Franc congolais
                            </option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date *</label>
                        <input type="date" name="date_entree" class="form-control"
                               value="<?= h($edition[$colDate] ?? $aujourdhui) ?>" required>
                    </div>

                    <?php if ($colObservation): ?>
                        <div class="mb-3">
                            <label class="form-label">Observation</label>
                            <textarea name="observation" class="form-control" rows="3"
                                      placeholder="Informations supplémentaires..."><?= h($edition[$colObservation] ?? '') ?></textarea>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-save"></i>
                        <?= $edition ? 'Enregistrer les modifications' : 'Enregistrer l’entrée' ?>
                    </button>

                    <?php if ($edition): ?>
                        <a href="entrees.php" class="btn btn-outline-light w-100 mt-2">
                            Annuler
                        </a>
                    <?php endif; ?>

                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card-custom">

                <h5 class="mb-4" style="color:var(--or)">
                    <i class="bi bi-list-ul"></i> Historique des entrées
                </h5>

                <form method="GET" class="row g-2 mb-4">

                    <div class="col-md-6">
                        <input type="text" name="recherche" class="form-control"
                               placeholder="Rechercher un libellé..."
                               value="<?= h($recherche) ?>">
                    </div>

                    <div class="col-md-6">
                        <select name="devise" class="form-select">
                            <option value="">Toutes les devises</option>
                            <option value="USD" <?= $filtreDevise === 'USD' ? 'selected' : '' ?>>USD</option>
                            <option value="CDF" <?= $filtreDevise === 'CDF' ? 'selected' : '' ?>>CDF</option>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label small">Du</label>
                        <input type="date" name="date_debut" class="form-control"
                               value="<?= h($dateDebut) ?>">
                    </div>

                    <div class="col-md-5">
                        <label class="form-label small">Au</label>
                        <input type="date" name="date_fin" class="form-control"
                               value="<?= h($dateFin) ?>">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-gold w-100" title="Filtrer">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>

                </form>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Libellé</th>
                                <th>Montant</th>
                                <th>Devise</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (count($entrees) > 0): ?>

                            <?php foreach ($entrees as $e): ?>
                                <tr>
                                    <td><?= h($e[$colDate]) ?></td>

                                    <td>
                                        <strong><?= h($e[$colLibelle]) ?></strong>
                                        <?php if ($colObservation && !empty($e[$colObservation])): ?>
                                            <div class="small text-secondary">
                                                <?= h($e[$colObservation]) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="fw-bold">
                                        <?= montantFormat($e[$colMontant]) ?>
                                    </td>

                                    <td>
                                        <span class="badge <?= $e[$colDevise] === 'USD' ? 'badge-usd' : 'badge-cdf' ?>">
                                            <?= h($e[$colDevise]) ?>
                                        </span>
                                    </td>

                                    <td class="text-nowrap">
                                        <a href="entrees.php?modifier=<?= (int)$e[$colId] ?>"
                                           class="btn-action" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Voulez-vous vraiment supprimer cette entrée ?')">
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="id"
                                                   value="<?= (int)$e[$colId] ?>">
                                            <button type="submit" class="btn-action" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="empty">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Aucune entrée financière trouvée.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="small text-secondary mt-3">
                    <?= count($entrees) ?> entrée(s) affichée(s).
                </div>

            </div>
        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>