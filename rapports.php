<?php
require_once 'auth.php';
require_once 'connexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function montant($v) {
    return number_format((float)$v, 2, '.', ' ');
}

/* Détecter les colonnes d'une table */
function colonneDisponible(PDO $pdo, string $table, array $possibles): ?string {
    $colonnes = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($possibles as $colonne) {
        if (in_array($colonne, $colonnes, true)) {
            return $colonne;
        }
    }

    return null;
}

/* Calculer une période */
$periode = $_GET['periode'] ?? 'mensuel';
$dateReference = $_GET['date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReference)) {
    $dateReference = date('Y-m-d');
}

$timestamp = strtotime($dateReference);

switch ($periode) {
    case 'journalier':
        $debut = date('Y-m-d', $timestamp);
        $fin = $debut;
        $titrePeriode = "Rapport journalier";
        break;

    case 'hebdomadaire':
        $debut = date('Y-m-d', strtotime('monday this week', $timestamp));
        $fin = date('Y-m-d', strtotime('sunday this week', $timestamp));
        $titrePeriode = "Rapport hebdomadaire";
        break;

    case 'annuel':
        $debut = date('Y-01-01', $timestamp);
        $fin = date('Y-12-31', $timestamp);
        $titrePeriode = "Rapport annuel";
        break;

    case 'personnalise':
        $debut = $_GET['debut'] ?? date('Y-m-01');
        $fin = $_GET['fin'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) {
            $debut = date('Y-m-01');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
            $fin = date('Y-m-d');
        }

        if ($debut > $fin) {
            [$debut, $fin] = [$fin, $debut];
        }

        $titrePeriode = "Rapport personnalisé";
        break;

    default:
        $periode = 'mensuel';
        $debut = date('Y-m-01', $timestamp);
        $fin = date('Y-m-t', $timestamp);
        $titrePeriode = "Rapport mensuel";
        break;
}

$totaux = [
    'ventes' => ['USD' => 0, 'CDF' => 0],
    'depenses' => ['USD' => 0, 'CDF' => 0],
    'entrees' => ['USD' => 0, 'CDF' => 0]
];

$erreurs = [];

/* Lecture sécurisée des totaux */
function calculerTotal(
    PDO $pdo,
    string $table,
    array $candidatsMontant,
    array $candidatsDevise,
    array $candidatsDate,
    string $debut,
    string $fin
): array {
    $resultat = ['USD' => 0, 'CDF' => 0];

    $montant = colonneDisponible($pdo, $table, $candidatsMontant);
    $devise = colonneDisponible($pdo, $table, $candidatsDevise);
    $date = colonneDisponible($pdo, $table, $candidatsDate);
    $id = colonneDisponible($pdo, $table, ['id']);
    $deleted = colonneDisponible($pdo, $table, ['deleted_at']);

    if (!$montant || !$devise || !$date) {
        throw new Exception("Colonnes nécessaires introuvables dans la table $table.");
    }

    $conditions = [
        "`$date` >= :debut",
        "`$date` < DATE_ADD(:fin, INTERVAL 1 DAY)",
        "`$devise` IN ('USD', 'CDF')"
    ];

    if ($deleted) {
        $conditions[] = "`$deleted` IS NULL";
    }

    $sql = "SELECT `$devise` AS devise, SUM(`$montant`) AS total
            FROM `$table`
            WHERE " . implode(' AND ', $conditions) .
            " GROUP BY `$devise`";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':debut' => $debut,
        ':fin' => $fin
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $monnaie = $ligne['devise'];

        if (isset($resultat[$monnaie])) {
            $resultat[$monnaie] = (float)$ligne['total'];
        }
    }

    return $resultat;
}

/* Ventes */
try {
    $totaux['ventes'] = calculerTotal(
        $pdo,
        'ventes',
        ['montant_total', 'montant', 'total'],
        ['devise', 'monnaie'],
        ['date_vente', 'date', 'date_operation'],
        $debut,
        $fin
    );
} catch (Throwable $e) {
    $erreurs[] = "Ventes : " . $e->getMessage();
}

/* Dépenses */
try {
    $totaux['depenses'] = calculerTotal(
        $pdo,
        'depenses',
        ['montant', 'montant_depense', 'total'],
        ['devise', 'monnaie'],
        ['date_depense', 'date', 'date_operation'],
        $debut,
        $fin
    );
} catch (Throwable $e) {
    $erreurs[] = "Dépenses : " . $e->getMessage();
}

/* Autres entrées */
try {
    $totaux['entrees'] = calculerTotal(
        $pdo,
        'entrees_financieres',
        ['montant', 'montant_entree', 'somme'],
        ['devise', 'monnaie'],
        ['date_entree', 'date_operation', 'date_enregistrement', 'date'],
        $debut,
        $fin
    );
} catch (Throwable $e) {
    $erreurs[] = "Autres entrées : " . $e->getMessage();
}

/* Solde opérationnel */
$solde = ['USD' => 0, 'CDF' => 0];

foreach (['USD', 'CDF'] as $devise) {
    $solde[$devise] =
        $totaux['ventes'][$devise]
        + $totaux['entrees'][$devise]
        - $totaux['depenses'][$devise];
}

/* Activité récente */
$activites = [];

try {
    $activites = $pdo->query("
        SELECT date_vente AS date_operation,
               'Vente' AS type_operation,
               montant_total AS montant,
               devise
        FROM ventes
        WHERE deleted_at IS NULL
        ORDER BY date_vente DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // L'historique récent reste facultatif.
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapports - DINPE HOUSE</title>

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
    font-size:24px;
    font-weight:bold;
    margin-top:10px;
}

.stat-icon {
    font-size:25px;
    color:var(--or);
}

.form-label {
    color:#ddd;
}

.form-control,
.form-select {
    background:#111216;
    border:1px solid #393a40;
    color:white;
    padding:10px;
}

.form-control:focus,
.form-select:focus {
    background:#111216;
    color:white;
    border-color:var(--or);
    box-shadow:0 0 0 .2rem #d6b45c22;
}

.form-select option {
    background:#17181d;
}

.btn-gold {
    background:var(--or);
    color:#111;
    border:none;
    font-weight:bold;
    padding:10px 17px;
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
}

.table thead th {
    color:var(--or);
    background:#25262c;
    border-color:#383940;
}

.table td {
    background:#1b1c22;
    border-color:#33343a;
}

.solde-positif { color:#55cf91; }
.solde-negatif { color:#ff7777; }

@media print {
    body { background:white !important; color:black !important; }
    .sidebar, .no-print, .btn { display:none !important; }
    .main { margin:0; padding:0; }
    .card-custom { background:white !important; color:black !important; border:1px solid #ccc; }
    .stat-title, .subtitle { color:#444 !important; }
    .stat-value, .page-title { color:#111 !important; }
    .table td, .table th { background:white !important; color:black !important; }
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
        <a href="rapports.php" class="active"><i class="bi bi-bar-chart"></i> Rapports</a>
        <a href="historique.php"><i class="bi bi-clock-history"></i> Historique</a>
        <a href="corbeille.php"><i class="bi bi-trash"></i> Corbeille</a>
        <a href="parametres.php"><i class="bi bi-gear"></i> Paramètres</a>
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
    </nav>
</aside>

<main class="main">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <div class="page-title">
                <i class="bi bi-bar-chart-fill"></i> Rapports financiers
            </div>
            <div class="subtitle">
                Analyse des activités financières de DINPE HOUSE.
            </div>
        </div>

        <button onclick="window.print()" class="btn btn-gold no-print">
            <i class="bi bi-printer"></i> Imprimer le rapport
        </button>
    </div>

    <?php if ($erreurs): ?>
        <div class="alert alert-warning">
            <strong><i class="bi bi-exclamation-triangle"></i> Attention :</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($erreurs as $erreur): ?>
                    <li><?= h($erreur) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="small mt-2">
                Les totaux des modules concernés peuvent être incomplets. Nous vérifierons les colonnes de ta base si nécessaire.
            </div>
        </div>
    <?php endif; ?>

    <div class="card-custom mb-4 no-print">
        <h5 class="mb-3" style="color:var(--or)">
            <i class="bi bi-calendar3"></i> Choisir la période
        </h5>

        <form method="GET" class="row g-3 align-items-end">

            <div class="col-md-3">
                <label class="form-label">Type de rapport</label>
                <select name="periode" id="periode" class="form-select" onchange="afficherDates()">
                    <option value="journalier" <?= $periode === 'journalier' ? 'selected' : '' ?>>Journalier</option>
                    <option value="hebdomadaire" <?= $periode === 'hebdomadaire' ? 'selected' : '' ?>>Hebdomadaire</option>
                    <option value="mensuel" <?= $periode === 'mensuel' ? 'selected' : '' ?>>Mensuel</option>
                    <option value="annuel" <?= $periode === 'annuel' ? 'selected' : '' ?>>Annuel</option>
                    <option value="personnalise" <?= $periode === 'personnalise' ? 'selected' : '' ?>>Personnalisé</option>
                </select>
            </div>

            <div class="col-md-3" id="dateReference">
                <label class="form-label">Date de référence</label>
                <input type="date" name="date" class="form-control"
                       value="<?= h($dateReference) ?>">
            </div>

            <div class="col-md-3" id="dateDebut" style="<?= $periode === 'personnalise' ? '' : 'display:none' ?>">
                <label class="form-label">Date de début</label>
                <input type="date" name="debut" class="form-control"
                       value="<?= h($debut) ?>">
            </div>

            <div class="col-md-3" id="dateFin" style="<?= $periode === 'personnalise' ? '' : 'display:none' ?>">
                <label class="form-label">Date de fin</label>
                <input type="date" name="fin" class="form-control"
                       value="<?= h($fin) ?>">
            </div>

            <div class="col-md-3">
                <button type="submit" class="btn btn-gold w-100">
                    <i class="bi bi-search"></i> Afficher
                </button>
            </div>

        </form>
    </div>

    <div class="mb-3">
        <h5 style="color:var(--or)"><?= h($titrePeriode) ?></h5>
        <div class="text-secondary">
            Du <?= date('d/m/Y', strtotime($debut)) ?>
            au <?= date('d/m/Y', strtotime($fin)) ?>
        </div>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-6 col-xl-3">
            <div class="card-custom">
                <div class="d-flex justify-content-between">
                    <div class="stat-title">Total des ventes</div>
                    <i class="bi bi-cart-check stat-icon"></i>
                </div>
                <div class="stat-value">$ <?= montant($totaux['ventes']['USD']) ?></div>
                <div class="text-secondary small mt-2">
                    <?= montant($totaux['ventes']['CDF']) ?> CDF
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card-custom">
                <div class="d-flex justify-content-between">
                    <div class="stat-title">Total des dépenses</div>
                    <i class="bi bi-wallet2 stat-icon"></i>
                </div>
                <div class="stat-value">$ <?= montant($totaux['depenses']['USD']) ?></div>
                <div class="text-secondary small mt-2">
                    <?= montant($totaux['depenses']['CDF']) ?> CDF
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card-custom">
                <div class="d-flex justify-content-between">
                    <div class="stat-title">Autres entrées</div>
                    <i class="bi bi-cash-stack stat-icon"></i>
                </div>
                <div class="stat-value">$ <?= montant($totaux['entrees']['USD']) ?></div>
                <div class="text-secondary small mt-2">
                    <?= montant($totaux['entrees']['CDF']) ?> CDF
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card-custom">
                <div class="d-flex justify-content-between">
                    <div class="stat-title">Solde opérationnel</div>
                    <i class="bi bi-graph-up-arrow stat-icon"></i>
                </div>
                <div class="stat-value <?= $solde['USD'] >= 0 ? 'solde-positif' : 'solde-negatif' ?>">
                    $ <?= montant($solde['USD']) ?>
                </div>
                <div class="text-secondary small mt-2">
                    <span class="<?= $solde['CDF'] >= 0 ? 'solde-positif' : 'solde-negatif' ?>">
                        <?= montant($solde['CDF']) ?> CDF
                    </span>
                </div>
            </div>
        </div>

    </div>

    <div class="card-custom mb-4">
        <h5 class="mb-4" style="color:var(--or)">
            <i class="bi bi-calculator"></i> Récapitulatif financier
        </h5>

        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Opération</th>
                        <th class="text-end">USD ($)</th>
                        <th class="text-end">CDF</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td>Total des ventes</td>
                        <td class="text-end"><?= montant($totaux['ventes']['USD']) ?></td>
                        <td class="text-end"><?= montant($totaux['ventes']['CDF']) ?></td>
                    </tr>

                    <tr>
                        <td>Autres entrées financières</td>
                        <td class="text-end"><?= montant($totaux['entrees']['USD']) ?></td>
                        <td class="text-end"><?= montant($totaux['entrees']['CDF']) ?></td>
                    </tr>

                    <tr>
                        <td>Total des dépenses</td>
                        <td class="text-end"><?= montant($totaux['depenses']['USD']) ?></td>
                        <td class="text-end"><?= montant($totaux['depenses']['CDF']) ?></td>
                    </tr>

                    <tr class="fw-bold">
                        <td>Solde opérationnel</td>
                        <td class="text-end"><?= montant($solde['USD']) ?> $</td>
                        <td class="text-end"><?= montant($solde['CDF']) ?> CDF</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="small text-secondary">
            Le solde opérationnel est calculé séparément dans chaque devise.
            Aucune conversion USD/CDF n'est effectuée.
        </div>
    </div>

    <div class="card-custom">
        <h5 class="mb-3" style="color:var(--or)">
            <i class="bi bi-info-circle"></i> Comprendre ce rapport
        </h5>

        <p class="text-secondary mb-2">
            Les montants présentés correspondent aux opérations enregistrées
            pendant la période sélectionnée.
        </p>

        <p class="text-secondary mb-0">
            Pour obtenir le bénéfice net, il faudra également tenir compte du
            coût des produits vendus et des éventuelles autres charges.
        </p>
    </div>

</main>

<script>
function afficherDates() {
    const periode = document.getElementById('periode').value;
    const personnalise = periode === 'personnalise';

    document.getElementById('dateReference').style.display =
        personnalise ? 'none' : 'block';

    document.getElementById('dateDebut').style.display =
        personnalise ? 'block' : 'none';

    document.getElementById('dateFin').style.display =
        personnalise ? 'block' : 'none';
}
</script>

</body>
</html>