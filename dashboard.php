

<?php
require_once "auth.php";
require_once "connexion.php";
// =====================================
// FONCTIONS
// =====================================
function montant($valeur, $devise) {
    return number_format((float)$valeur, 2, ',', ' ') . ' ' . $devise;
}
function somme($pdo, $sql, $devise = null) {
    $stmt = $pdo->prepare($sql);
    if ($devise !== null) {
        $stmt->execute([$devise]);
    } else {
        $stmt->execute();
    }
    return $stmt->fetchColumn() ?: 0;
}
// =====================================
// STATISTIQUES GENERALES
// =====================================
$total_produits = somme(
    $pdo,
    "SELECT COUNT(*) FROM produits WHERE deleted_at IS NULL"
);
$stock_total = somme(
    $pdo,
    "SELECT COALESCE(SUM(quantite_stock),0)
     FROM produits WHERE deleted_at IS NULL"
);
$stock_faible = somme(
    $pdo,
    "SELECT COUNT(*) FROM produits
     WHERE quantite_stock <= stock_minimum
     AND deleted_at IS NULL"
);
// =====================================
// VENTES DU JOUR
// =====================================
$ventes_usd = somme(
    $pdo,
    "SELECT COALESCE(SUM(montant_total),0)
     FROM ventes
     WHERE DATE(date_vente)=CURDATE()
     AND devise='USD'
     AND statut='Validee'
     AND deleted_at IS NULL"
);
$ventes_cdf = somme(
    $pdo,
    "SELECT COALESCE(SUM(montant_total),0)
     FROM ventes
     WHERE DATE(date_vente)=CURDATE()
     AND devise='CDF'
     AND statut='Validee'
     AND deleted_at IS NULL"
);
// =====================================
// DEPENSES DU JOUR
// =====================================
$depenses_usd = somme(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM depenses
     WHERE DATE(date_depense)=CURDATE()
     AND devise='USD'
     AND deleted_at IS NULL"
);
$depenses_cdf = somme(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM depenses
     WHERE DATE(date_depense)=CURDATE()
     AND devise='CDF'
     AND deleted_at IS NULL"
);
// =====================================
// AUTRES ENTREES FINANCIERES
// =====================================
$entrees_usd = somme(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM entrees_financieres
     WHERE DATE(date_entree)=CURDATE()
     AND devise='USD'
     AND deleted_at IS NULL"
);
$entrees_cdf = somme(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM entrees_financieres
     WHERE DATE(date_entree)=CURDATE()
     AND devise='CDF'
     AND deleted_at IS NULL"
);
// =====================================
// SOLDE DU JOUR
// =====================================
$solde_usd = $ventes_usd + $entrees_usd - $depenses_usd;
$solde_cdf = $ventes_cdf + $entrees_cdf - $depenses_cdf;
// =====================================
// ENTREES ET SORTIES DE STOCK
// =====================================
$entrees_stock = somme(
    $pdo,
    "SELECT COALESCE(SUM(quantite),0)
     FROM mouvements_stock
     WHERE DATE(date_mouvement)=CURDATE()
     AND type_mouvement='Entree'"
);
$sorties_stock = somme(
    $pdo,
    "SELECT COALESCE(SUM(quantite),0)
     FROM mouvements_stock
     WHERE DATE(date_mouvement)=CURDATE()
     AND type_mouvement='Sortie'"
);
// =====================================
// DERNIERES VENTES
// =====================================
$stmt = $pdo->query(
    "SELECT numero_vente, date_vente,
            montant_total, devise, statut
     FROM ventes
     WHERE deleted_at IS NULL
     ORDER BY date_vente DESC
     LIMIT 7"
);
$dernieres_ventes = $stmt->fetchAll();
// =====================================
// PRODUITS EN ALERTE
// =====================================
$stmt = $pdo->query(
    "SELECT nom_produit, quantite_stock,
            stock_minimum, unite
     FROM produits
     WHERE quantite_stock <= stock_minimum
     AND deleted_at IS NULL
     ORDER BY quantite_stock ASC
     LIMIT 7"
);
$produits_faibles = $stmt->fetchAll();
// =====================================
// GRAPHIQUE DES VENTES
// 7 DERNIERS JOURS
// =====================================
$stmt = $pdo->query(
    "SELECT DATE(date_vente) AS jour,
            devise,
            SUM(montant_total) AS total
     FROM ventes
     WHERE date_vente >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     AND statut='Validee'
     AND deleted_at IS NULL
     GROUP BY DATE(date_vente), devise
     ORDER BY jour ASC"
);
$donnees = $stmt->fetchAll();
$jours = [];
$graph_usd = [];
$graph_cdf = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $jours[] = date('d/m', strtotime($date));
    $graph_usd[$date] = 0;
    $graph_cdf[$date] = 0;
}
foreach ($donnees as $ligne) {
    if ($ligne['devise'] == 'USD') {
        $graph_usd[$ligne['jour']] = (float)$ligne['total'];
    }
    if ($ligne['devise'] == 'CDF') {
        $graph_cdf[$ligne['jour']] = (float)$ligne['total'];
    }
}
$nom_etablissement = "DINPE HOUSE";
try {
    $param = $pdo->query(
        "SELECT nom_etablissement FROM parametres LIMIT 1"
    )->fetch();
    if (!empty($param['nom_etablissement'])) {
        $nom_etablissement = $param['nom_etablissement'];
    }
} catch (PDOException $e) {
    // Nom par défaut
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | DINPE HOUSE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --gold: #d4af37;
    --dark: #151515;
    --sidebar: #191919;
    --background: #f5f5f5;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    background: var(--background);
    font-family: Arial, sans-serif;
}
/* SIDEBAR */
.sidebar {
    width: 255px;
    height: 100vh;
    background: var(--sidebar);
    position: fixed;
    top: 0;
    left: 0;
    padding: 25px 15px;
    overflow-y: auto;
    color: white;
    z-index: 1000;
}
.brand {
    color: var(--gold);
    font-size: 24px;
    font-weight: bold;
    text-align: center;
    padding-bottom: 25px;
    border-bottom: 1px solid #393939;
    letter-spacing: 2px;
}
.brand small {
    display: block;
    color: #aaa;
    font-size: 11px;
    letter-spacing: 1px;
    margin-top: 5px;
}
.menu-title {
    color: #888;
    font-size: 11px;
    margin: 25px 12px 10px;
    text-transform: uppercase;
}
.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #ccc;
    padding: 12px;
    margin-bottom: 5px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 14px;
    transition: .3s;
}
.sidebar a:hover,
.sidebar a.active {
    background: var(--gold);
    color: #111;
}
.sidebar a i {
    font-size: 17px;
}
/* CONTENU */
.main {
    margin-left: 255px;
    padding: 25px;
}
.topbar {
    background: white;
    padding: 17px 22px;
    border-radius: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}
.topbar h5 {
    margin: 0;
    font-weight: bold;
}
.admin {
    background: #fff8df;
    padding: 9px 15px;
    border-radius: 30px;
    color: #80620b;
    font-size: 13px;
}
/* CARTES */
.stat-card {
    background: white;
    padding: 20px;
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    height: 100%;
    border-left: 4px solid var(--gold);
}
.stat-card h6 {
    color: #888;
    font-size: 13px;
    margin-bottom: 12px;
}
.stat-card h3 {
    font-size: 23px;
    font-weight: bold;
    margin: 0;
    color: #222;
}
.stat-card .icon {
    width: 42px;
    height: 42px;
    display: flex;
    justify-content: center;
    align-items: center;
    background: #fff5d6;
    color: #a47c00;
    border-radius: 10px;
    font-size: 21px;
    margin-bottom: 15px;
}
.section-card {
    background: white;
    padding: 22px;
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    margin-bottom: 22px;
}
.section-card h5 {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 20px;
}
.table th {
    background: #f8f8f8;
    color: #555;
    font-size: 12px;
}
.table td {
    font-size: 13px;
    vertical-align: middle;
}
.gold {
    color: #a47c00;
    font-weight: bold;
}
.footer {
    text-align: center;
    padding: 20px;
    color: #999;
    font-size: 12px;
}
@media(max-width: 900px) {
    .sidebar {
        width: 70px;
        padding: 15px 8px;
    }
    .brand {
        font-size: 10px;
        letter-spacing: 0;
    }
    .brand small,
    .menu-title,
    .sidebar a span {
        display: none;
    }
    .sidebar a {
        justify-content: center;
        padding: 13px 5px;
    }
    .main {
        margin-left: 70px;
        padding: 12px;
    }
    .topbar {
        padding: 14px;
    }
    .topbar h5 {
        font-size: 14px;
    }
}
</style>
</head>
<body>
<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="brand">
        DINPE HOUSE
        <small>GESTION MINI-BAR</small>
    </div>
    <div class="menu-title">Principal</div>
    <a href="dashboard.php" class="active">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>
    <a href="produits.php">
        <i class="bi bi-cup-straw"></i>
        <span>Produits</span>
    </a>
    <a href="approvisionnement.php">
        <i class="bi bi-box-arrow-in-down"></i>
        <span>Entrées de stock</span>
    </a>
    <a href="ventes.php">
        <i class="bi bi-cart-check"></i>
        <span>Ventes / Sorties</span>
    </a>
    <div class="menu-title">Finances</div>
    <a href="depenses.php">
        <i class="bi bi-wallet2"></i>
        <span>Dépenses</span>
    </a>
    <a href="entrees.php">
        <i class="bi bi-cash-stack"></i>
        <span>Entrées financières</span>
    </a>
    <a href="rapports.php">
        <i class="bi bi-file-earmark-bar-graph"></i>
        <span>Rapports</span>
    </a>
    <div class="menu-title">Administration</div>
    <a href="historique.php">
        <i class="bi bi-clock-history"></i>
        <span>Historique</span>
    </a>
    <a href="corbeille.php">
        <i class="bi bi-trash"></i>
        <span>Corbeille</span>
    </a>
    <a href="parametres.php">
        <i class="bi bi-gear"></i>
        <span>Paramètres</span>
    </a>
    <a href="logout.php">
        <i class="bi bi-box-arrow-right"></i>
        <span>Déconnexion</span>
    </a>
</aside>
<!-- CONTENU PRINCIPAL -->
<main class="main">
    <div class="topbar">
        <div>
            <h5>Tableau de bord</h5>
            <small class="text-muted">
                Bienvenue chez <?= htmlspecialchars($nom_etablissement) ?>
            </small>
        </div>
        <div class="admin">
            <i class="bi bi-person-circle"></i>
            <?= htmlspecialchars($_SESSION['admin_nom'] ?? 'Administrateur') ?>
        </div>
    </div>
    <!-- FINANCES -->
    <h6 class="mb-3 fw-bold">SITUATION FINANCIÈRE DU JOUR</h6>
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-currency-dollar"></i>
                </div>
                <h6>Ventes du jour</h6>
                <h3><?= montant($ventes_usd, 'USD') ?></h3>
                <small class="text-muted">
                    <?= montant($ventes_cdf, 'FC') ?>
                </small>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-wallet"></i>
                </div>
                <h6>Dépenses du jour</h6>
                <h3><?= montant($depenses_usd, 'USD') ?></h3>
                <small class="text-muted">
                    <?= montant($depenses_cdf, 'FC') ?>
                </small>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-plus-circle"></i>
                </div>
                <h6>Autres entrées</h6>
                <h3><?= montant($entrees_usd, 'USD') ?></h3>
                <small class="text-muted">
                    <?= montant($entrees_cdf, 'FC') ?>
                </small>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <h6>Solde net du jour</h6>
                <h3 class="<?= $solde_usd >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= montant($solde_usd, 'USD') ?>
                </h3>
                <small class="<?= $solde_cdf >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= montant($solde_cdf, 'FC') ?>
                </small>
            </div>
        </div>
    </div>
    <!-- STOCK -->
    <h6 class="mb-3 fw-bold">GESTION DU STOCK</h6>
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-box-seam"></i>
                </div>
                <h6>Produits enregistrés</h6>
                <h3><?= $total_produits ?></h3>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-boxes"></i>
                </div>
                <h6>Stock disponible</h6>
                <h3><?= number_format($stock_total, 0, ',', ' ') ?></h3>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-box-arrow-in-down"></i>
                </div>
                <h6>Entrées du jour</h6>
                <h3 class="text-success">
                    <?= number_format($entrees_stock, 0, ',', ' ') ?>
                </h3>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="icon">
                    <i class="bi bi-box-arrow-up"></i>
                </div>
                <h6>Sorties du jour</h6>
                <h3 class="text-primary">
                    <?= number_format($sorties_stock, 0, ',', ' ') ?>
                </h3>
            </div>
        </div>
    </div>
    <!-- GRAPHIQUE -->
    <div class="row">
        <div class="col-lg-8">
            <div class="section-card">
                <h5>
                    <i class="bi bi-bar-chart-line gold"></i>
                    Évolution des ventes (7 jours)
                </h5>
                <canvas id="ventesChart" height="110"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="section-card">
                <h5>
                    <i class="bi bi-exclamation-triangle text-warning"></i>
                    Alertes de stock
                </h5>
                <?php if (count($produits_faibles) > 0): ?>
                    <?php foreach ($produits_faibles as $produit): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <strong style="font-size:13px;">
                                    <?= htmlspecialchars($produit['nom_produit']) ?>
                                </strong>
                                <div class="text-muted" style="font-size:11px;">
                                    Seuil : <?= $produit['stock_minimum'] ?>
                                </div>
                            </div>
                            <span class="badge bg-danger">
                                <?= $produit['quantite_stock'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-success">
                        Tous les produits ont un stock suffisant.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- DERNIERES VENTES -->
    <div class="section-card">
        <h5>
            <i class="bi bi-receipt gold"></i>
            Dernières ventes enregistrées
        </h5>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>N° Vente</th>
                        <th>Date</th>
                        <th>Montant</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($dernieres_ventes) > 0): ?>
                        <?php foreach ($dernieres_ventes as $vente): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($vente['numero_vente']) ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($vente['date_vente'])) ?>
                                </td>
                                <td class="gold">
                                    <?= montant($vente['montant_total'], $vente['devise']) ?>
                                </td>
                                <td>
                                    <?php if ($vente['statut'] == 'Validee'): ?>
                                        <span class="badge bg-success">
                                            Validée
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            Annulée
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                Aucune vente enregistrée pour le moment.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="footer">
        © <?= date('Y') ?> DINPE HOUSE - Système de gestion Mini-Bar
    </div>
</main>
<script>
// GRAPHIQUE DES VENTES
const ctx = document.getElementById('ventesChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($jours) ?>,
        datasets: [
            {
                label: 'Ventes USD',
                data: <?= json_encode(array_values($graph_usd)) ?>,
                borderColor: '#d4af37',
                backgroundColor: 'rgba(212,175,55,.15)',
                fill: true,
                tension: .4
            },
            {
                label: 'Ventes FC',
                data: <?= json_encode(array_values($graph_cdf)) ?>,
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,.08)',
                fill: true,
                tension: .4
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>
</body>
</html>