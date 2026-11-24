<?php
require_once 'auth.php';
require_once 'connexion.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function nomColonne($nom) {
    return '`' . str_replace('`', '``', $nom) . '`';
}
$erreur = '';
$succes = '';
$elements = [];
$colonnes = [];
$champsRecherche = [];
$colId = null;
$colDate = null;
$recherche = trim($_GET['recherche'] ?? '');
try {
    // Vérifier la structure de la corbeille
    $colonnes = $pdo->query("SHOW COLUMNS FROM corbeille")
                    ->fetchAll(PDO::FETCH_ASSOC);
    $noms = array_column($colonnes, 'Field');
    if (!$noms) {
        throw new Exception("La table corbeille ne contient aucune colonne.");
    }
    foreach (['id', 'id_corbeille'] as $candidate) {
        if (in_array($candidate, $noms, true)) {
            $colId = $candidate;
            break;
        }
    }
    foreach (
        ['date_suppression', 'date_action', 'created_at', 'date_creation', 'date']
        as $candidate
    ) {
        if (in_array($candidate, $noms, true)) {
            $colDate = $candidate;
            break;
        }
    }
    foreach ($colonnes as $colonne) {
        $type = strtolower($colonne['Type'] ?? '');
        if (
            strpos($type, 'varchar') !== false ||
            strpos($type, 'text') !== false ||
            strpos($type, 'char') !== false
        ) {
            $champsRecherche[] = $colonne['Field'];
        }
    }
    // Suppression définitive d'un élément de la corbeille
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'supprimer_definitivement') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$colId || $id <= 0) {
                throw new Exception("Identifiant invalide.");
            }
            $stmt = $pdo->prepare(
                "DELETE FROM corbeille WHERE " .
                nomColonne($colId) . " = :id"
            );
            $stmt->execute([':id' => $id]);
            $succes = "L'élément a été supprimé définitivement.";
        }
        if ($action === 'vider') {
            if (!$colId) {
                throw new Exception("Impossible d'identifier les éléments de la corbeille.");
            }
            if (($_POST['confirmation'] ?? '') !== 'VIDER') {
                throw new Exception("Confirmation incorrecte.");
            }
            $pdo->exec("DELETE FROM corbeille");
            $succes = "La corbeille a été vidée.";
        }
    }
    // Recherche
    $conditions = [];
    $params = [];
    if ($recherche !== '' && count($champsRecherche) > 0) {
        $parts = [];
        foreach ($champsRecherche as $i => $champ) {
            $param = ':r' . $i;
            $parts[] = nomColonne($champ) . " LIKE $param";
            $params[$param] = '%' . $recherche . '%';
        }
        $conditions[] = '(' . implode(' OR ', $parts) . ')';
    }
    $sql = "SELECT * FROM corbeille";
    if ($conditions) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    if ($colDate) {
        $sql .= " ORDER BY " . nomColonne($colDate) . " DESC";
    } elseif ($colId) {
        $sql .= " ORDER BY " . nomColonne($colId) . " DESC";
    }
    $sql .= " LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erreur = $e->getMessage();
}
function titreColonne($nom) {
    return ucfirst(str_replace('_', ' ', $nom));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Corbeille - DINPE HOUSE</title>
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
    font-size:27px;
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
.btn-danger-custom {
    background:#812d35;
    color:white;
    border:none;
    padding:7px 10px;
    border-radius:6px;
}
.btn-danger-custom:hover {
    background:#a83c47;
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
    max-width:300px;
    overflow-wrap:anywhere;
}
.empty {
    color:#999;
    text-align:center;
    padding:35px;
}
.warning-text {
    color:#e6b96b;
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
        <a href="historique.php"><i class="bi bi-clock-history"></i> Historique</a>
        <a href="corbeille.php" class="active"><i class="bi bi-trash"></i> Corbeille</a>
        <a href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
    </nav>
</aside>
<main class="main">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <div class="page-title">
                <i class="bi bi-trash3-fill"></i> Corbeille
            </div>
            <div class="subtitle">
                Consulte les éléments supprimés de DINPE HOUSE.
            </div>
        </div>
        <button onclick="window.print()" class="btn btn-gold no-print">
            <i class="bi bi-printer"></i> Imprimer
        </button>
    </div>
    <?php if ($succes): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle"></i> <?= h($succes) ?>
        </div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            <?= h($erreur) ?>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="stat-title">
                        <i class="bi bi-trash"></i> Éléments dans la corbeille
                    </div>
                    <div class="stat-value">
                        <?= count($elements) ?>
                    </div>
                    <div class="small text-secondary mt-2">
                        Maximum 300 éléments affichés.
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="stat-title">
                        <i class="bi bi-shield-exclamation"></i> Gestion des suppressions
                    </div>
                    <div class="mt-3 warning-text">
                        Les suppressions définitives sont irréversibles.
                    </div>
                </div>
            </div>
        </div>
        <div class="card-custom mb-4 no-print">
            <h5 style="color:var(--or)" class="mb-3">
                <i class="bi bi-search"></i> Rechercher dans la corbeille
            </h5>
            <form method="GET" class="row g-3">
                <div class="col-md-9">
                    <input type="text"
                           name="recherche"
                           class="form-control"
                           placeholder="Rechercher un élément..."
                           value="<?= h($recherche) ?>">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-gold w-100">
                        <i class="bi bi-search"></i> Rechercher
                    </button>
                </div>
            </form>
        </div>
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <h5 style="color:var(--or)" class="mb-0">
                    <i class="bi bi-archive"></i> Éléments supprimés
                </h5>
                <?php if (count($elements) > 0 && $colId): ?>
                    <button type="button"
                            class="btn btn-danger-custom no-print"
                            onclick="document.getElementById('formVider').style.display='block'">
                        <i class="bi bi-trash3"></i> Vider la corbeille
                    </button>
                <?php endif; ?>
            </div>
            <div id="formVider" class="alert alert-danger no-print" style="display:none">
                <strong>Attention !</strong>
                Cette opération supprimera définitivement tous les éléments de la corbeille.
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="vider">
                    <label class="form-label">
                        Tape VIDER pour confirmer
                    </label>
                    <input type="text"
                           name="confirmation"
                           class="form-control mb-3"
                           placeholder="VIDER"
                           required>
                    <button class="btn btn-danger">
                        Confirmer la suppression définitive
                    </button>
                    <button type="button"
                            class="btn btn-secondary"
                            onclick="document.getElementById('formVider').style.display='none'">
                        Annuler
                    </button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <?php foreach ($colonnes as $colonne): ?>
                                <th><?= h(titreColonne($colonne['Field'])) ?></th>
                            <?php endforeach; ?>
                            <?php if ($colId): ?>
                                <th class="no-print">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($elements) > 0): ?>
                        <?php foreach ($elements as $element): ?>
                            <tr>
                                <?php foreach ($colonnes as $colonne): ?>
                                    <?php
                                    $nom = $colonne['Field'];
                                    $valeur = $element[$nom] ?? '—';
                                    ?>
                                    <td>
                                        <?= h($valeur) ?>
                                    </td>
                                <?php endforeach; ?>
                                <?php if ($colId): ?>
                                    <td class="no-print">
                                        <form method="POST"
                                              onsubmit="return confirm('Supprimer définitivement cet élément ?')">
                                            <input type="hidden"
                                                   name="action"
                                                   value="supprimer_definitivement">
                                            <input type="hidden"
                                                   name="id"
                                                   value="<?= (int)$element[$colId] ?>">
                                            <button type="submit"
                                                    class="btn-danger-custom">
                                                <i class="bi bi-trash3"></i>
                                                Supprimer définitivement
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= count($colonnes) + ($colId ? 1 : 0) ?>"
                                class="empty">
                                <i class="bi bi-trash fs-1 d-block mb-3"></i>
                                La corbeille est vide.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-secondary mt-3">
                La restauration sera activée après vérification de la structure
                de la table corbeille et du format des données sauvegardées.
            </div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>