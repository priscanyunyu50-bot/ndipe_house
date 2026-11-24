<?php
require_once 'auth.php';
require_once 'connexion.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function h($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, 'UTF-8');
}
function nomColonneSecurise($nom) {
    return '`' . str_replace('`', '``', $nom) . '`';
}
$erreur = '';
$historique = [];
$colonnes = [];
$colonnesRecherche = [];
$colDate = null;
$colId = null;
$recherche = trim($_GET['recherche'] ?? '');
$dateDebut = $_GET['date_debut'] ?? '';
$dateFin = $_GET['date_fin'] ?? '';
try {
    // Vérifier la structure de la table
    $colonnes = $pdo->query("SHOW COLUMNS FROM historique_actions")
                    ->fetchAll(PDO::FETCH_ASSOC);
    if (!$colonnes) {
        throw new Exception("La table historique_actions ne contient aucune colonne.");
    }
    $nomsColonnes = array_column($colonnes, 'Field');
    // Identifier automatiquement les colonnes importantes
    $candidatsDate = [
        'date_action',
        'date_operation',
        'created_at',
        'date_creation',
        'date_enregistrement',
        'date'
    ];
    $candidatsId = ['id', 'id_action'];
    foreach ($candidatsDate as $nom) {
        if (in_array($nom, $nomsColonnes, true)) {
            $colDate = $nom;
            break;
        }
    }
    foreach ($candidatsId as $nom) {
        if (in_array($nom, $nomsColonnes, true)) {
            $colId = $nom;
            break;
        }
    }
    // Identifier les colonnes textuelles pour la recherche
    foreach ($colonnes as $colonne) {
        $type = strtolower($colonne['Type'] ?? '');
        if (
            strpos($type, 'varchar') !== false ||
            strpos($type, 'text') !== false ||
            strpos($type, 'char') !== false
        ) {
            $colonnesRecherche[] = $colonne['Field'];
        }
    }
    $conditions = [];
    $parametres = [];
    // Recherche générale
    if ($recherche !== '' && count($colonnesRecherche) > 0) {
        $conditionsRecherche = [];
        foreach ($colonnesRecherche as $index => $nom) {
            $param = ':recherche' . $index;
            $conditionsRecherche[] =
                nomColonneSecurise($nom) . " LIKE $param";
            $parametres[$param] = '%' . $recherche . '%';
        }
        $conditions[] = '(' . implode(' OR ', $conditionsRecherche) . ')';
    }
    // Filtre date de début
    if ($dateDebut !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
            throw new Exception("La date de début est invalide.");
        }
        if (!$colDate) {
            throw new Exception("Aucune colonne de date compatible n'a été trouvée.");
        }
        $conditions[] = nomColonneSecurise($colDate) . " >= :date_debut";
        $parametres[':date_debut'] = $dateDebut . ' 00:00:00';
    }
    // Filtre date de fin
    if ($dateFin !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
            throw new Exception("La date de fin est invalide.");
        }
        if (!$colDate) {
            throw new Exception("Aucune colonne de date compatible n'a été trouvée.");
        }
        $conditions[] = nomColonneSecurise($colDate) . " < DATE_ADD(:date_fin, INTERVAL 1 DAY)";
        $parametres[':date_fin'] = $dateFin;
    }
    $sql = "SELECT * FROM historique_actions";
    if ($conditions) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    // Tri par date ou identifiant
    if ($colDate) {
        $sql .= " ORDER BY " . nomColonneSecurise($colDate) . " DESC";
    } elseif ($colId) {
        $sql .= " ORDER BY " . nomColonneSecurise($colId) . " DESC";
    }
    $sql .= " LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametres);
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erreur = $e->getMessage();
}
function nomLisible($nom) {
    $nom = str_replace('_', ' ', $nom);
    return ucfirst($nom);
}
function valeurLisible($valeur) {
    if ($valeur === null || $valeur === '') {
        return '—';
    }
    if (is_string($valeur) && strlen($valeur) > 250) {
        return substr($valeur, 0, 250) . '...';
    }
    return $valeur;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Historique - DINPE HOUSE</title>
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
* {
    box-sizing:border-box;
}
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
.form-control {
    background:#111216;
    border:1px solid #393a40;
    color:white;
    padding:11px;
}
.form-control:focus {
    background:#111216;
    color:white;
    border-color:var(--or);
    box-shadow:0 0 0 .2rem #d6b45c22;
}
.form-control::placeholder {
    color:#777;
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
.btn-outline-gold {
    border:1px solid var(--or);
    color:var(--or);
}
.btn-outline-gold:hover {
    background:var(--or);
    color:#111;
}
.table {
    color:#eee;
    vertical-align:middle;
    margin-bottom:0;
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
    max-width:300px;
    overflow-wrap:anywhere;
}
.table tbody tr:hover td {
    background:#24252b;
}
.empty {
    color:#999;
    text-align:center;
    padding:35px;
}
.info-text {
    color:#999;
    font-size:13px;
}
@media print {
    body {
        background:white !important;
        color:black !important;
    }
    .sidebar,
    .no-print {
        display:none !important;
    }
    .main {
        margin:0;
        padding:0;
    }
    .card-custom {
        background:white !important;
        border:1px solid #ccc;
    }
    .page-title,
    .stat-value {
        color:black !important;
    }
    .table td,
    .table th {
        background:white !important;
        color:black !important;
    }
}
@media(max-width:850px) {
    .sidebar {
        width:100%;
        height:auto;
        position:relative;
    }
    .sidebar nav {
        display:grid;
        grid-template-columns:repeat(2,1fr);
    }
    .main {
        margin-left:0;
        padding:18px;
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
        <a href="entrees.php"><i class="bi bi-cash-stack"></i> Autres entrées</a>
        <a href="rapports.php"><i class="bi bi-bar-chart"></i> Rapports</a>
        <a href="historique.php" class="active"><i class="bi bi-clock-history"></i> Historique</a>
        <a href="corbeille.php"><i class="bi bi-trash"></i> Corbeille</a>
        <a href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
    </nav>
</aside>
<main class="main">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <div class="page-title">
                <i class="bi bi-clock-history"></i> Historique des activités
            </div>
            <div class="subtitle">
                Consulte les opérations enregistrées dans DINPE HOUSE.
            </div>
        </div>
        <button onclick="window.print()" class="btn btn-gold no-print">
            <i class="bi bi-printer"></i> Imprimer
        </button>
    </div>
    <?php if ($erreur): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Erreur :</strong> <?= h($erreur) ?>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="stat-title">
                        <i class="bi bi-list-check"></i> Opérations affichées
                    </div>
                    <div class="stat-value">
                        <?= count($historique) ?>
                    </div>
                    <div class="info-text mt-2">
                        Maximum 300 opérations affichées.
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="stat-title">
                        <i class="bi bi-calendar-event"></i> Consultation
                    </div>
                    <div class="stat-value">
                        <?= date('d/m/Y') ?>
                    </div>
                    <div class="info-text mt-2">
                        Date de consultation de l'historique.
                    </div>
                </div>
            </div>
        </div>
        <div class="card-custom mb-4 no-print">
            <h5 class="mb-3" style="color:var(--or)">
                <i class="bi bi-funnel"></i> Rechercher une opération
            </h5>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Recherche</label>
                    <input type="text"
                           name="recherche"
                           class="form-control"
                           placeholder="Action, utilisateur, description..."
                           value="<?= h($recherche) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date de début</label>
                    <input type="date"
                           name="date_debut"
                           class="form-control"
                           value="<?= h($dateDebut) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date de fin</label>
                    <input type="date"
                           name="date_fin"
                           class="form-control"
                           value="<?= h($dateFin) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-gold w-100">
                        <i class="bi bi-search"></i> Filtrer
                    </button>
                </div>
            </form>
            <a href="historique.php" class="btn btn-outline-gold btn-sm mt-3">
                <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
            </a>
        </div>
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0" style="color:var(--or)">
                    <i class="bi bi-journal-text"></i> Journal des opérations
                </h5>
                <span class="info-text">
                    <?= count($historique) ?> résultat(s)
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <?php foreach ($colonnes as $colonne): ?>
                                <th><?= h(nomLisible($colonne['Field'])) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($historique) > 0): ?>
                        <?php foreach ($historique as $ligne): ?>
                            <tr>
                                <?php foreach ($colonnes as $colonne): ?>
                                    <?php
                                    $nom = $colonne['Field'];
                                    $valeur = $ligne[$nom] ?? null;
                                    ?>
                                    <td>
                                        <?= h(valeurLisible($valeur)) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= max(1, count($colonnes)) ?>" class="empty">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                Aucune opération trouvée.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="info-text mt-3">
                Cette page consulte les données de la table historique_actions.
                Elle n'ajoute, ne modifie et ne supprime aucune opération.
            </div>
        </div>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>