<?php
require_once 'auth.php';
require_once 'connexion.php';
$message = '';
$erreur = '';
/* TAUX DE CHANGE */
$taux = 2800;
try {
    $reqTaux = $pdo->query("SELECT valeur FROM parametres WHERE cle_parametre = 'taux_change' LIMIT 1");
    $resultatTaux = $reqTaux->fetchColumn();
    if ($resultatTaux && $resultatTaux > 0) {
        $taux = (float)$resultatTaux;
    }
} catch (Throwable $e) {
    // Le taux par défaut reste utilisé si cette configuration n'existe pas.
}
/* ENREGISTREMENT D'UNE VENTE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer_vente'])) {
    try {
        $pdo->beginTransaction();
        $dateVente = $_POST['date_vente'] ?? date('Y-m-d');
        $devise = $_POST['devise'] ?? 'USD';
        $modePaiement = $_POST['mode_paiement'] ?? 'Cash';
        $tauxVente = (float)($_POST['taux_change'] ?? $taux);
        $observation = trim($_POST['observation'] ?? '');
        if ($tauxVente <= 0) {
            throw new Exception("Le taux de change est invalide.");
        }
        $produits = $_POST['id_produit'] ?? [];
        $quantites = $_POST['quantite'] ?? [];
        $emballages = $_POST['emballage'] ?? [];
        $prixUnitaires = $_POST['prix_unitaire'] ?? [];
        if (empty($produits)) {
            throw new Exception("Ajoutez au moins un produit à la vente.");
        }
        $numero = 'VTE-' . date('YmdHis');
        $total = 0;
        $lignes = [];
        foreach ($produits as $i => $idProduit) {
            $idProduit = (int)$idProduit;
            $quantite = (int)($quantites[$i] ?? 0);
            $emballage = $emballages[$i] ?? 'Unité';
            $prix = (float)($prixUnitaires[$i] ?? 0);
            if ($idProduit <= 0 || $quantite <= 0 || $prix < 0) {
                continue;
            }
            $stmt = $pdo->prepare("
                SELECT id, nom_produit, quantite_stock, quantite_par_emballage
                FROM produits
                WHERE id = ? AND deleted_at IS NULL
                FOR UPDATE
            ");
            $stmt->execute([$idProduit]);
            $produit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$produit) {
                throw new Exception("Un produit sélectionné n'existe plus.");
            }
            $unitesParEmballage = max(1, (int)$produit['quantite_par_emballage']);
            $quantiteUnitaire = ($emballage === 'Carton')
                ? $unitesParEmballage
                : 1;
            $quantiteTotale = $quantite * $quantiteUnitaire;
            if ((int)$produit['quantite_stock'] < $quantiteTotale) {
                throw new Exception(
                    "Stock insuffisant pour " . $produit['nom_produit'] .
                    ". Stock disponible : " . $produit['quantite_stock'] . " unités."
                );
            }
            $sousTotal = $quantite * $prix;
            $total += $sousTotal;
            $lignes[] = [
                'id_produit' => $idProduit,
                'quantite' => $quantiteTotale,
                'prix_unitaire' => $prix / $quantiteUnitaire,
                'sous_total' => $sousTotal,
                'emballage' => $emballage
            ];
        }
        if (empty($lignes)) {
            throw new Exception("Aucune ligne de vente valide.");
        }
        /* EN-TÊTE DE LA VENTE */
        $stmt = $pdo->prepare("
            INSERT INTO ventes
            (numero, date_vente, montant_total, devise, taux_change, mode_paiement, observation, statut)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Validée')
        ");
        $stmt->execute([
            $numero,
            $dateVente,
            $total,
            $devise,
            $tauxVente,
            $modePaiement,
            $observation
        ]);
        $idVente = (int)$pdo->lastInsertId();
        /* DÉTAILS ET DIMINUTION DU STOCK */
        foreach ($lignes as $ligne) {
            $stmt = $pdo->prepare("
                INSERT INTO details_vente
                (id_vente, id_produit, quantite, prix_unitaire, sous_total)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $idVente,
                $ligne['id_produit'],
                $ligne['quantite'],
                $ligne['prix_unitaire'],
                $ligne['sous_total']
            ]);
            $stmt = $pdo->prepare("
                UPDATE produits
                SET quantite_stock = quantite_stock - ?
                WHERE id = ?
            ");
            $stmt->execute([
                $ligne['quantite'],
                $ligne['id_produit']
            ]);
        }
        $pdo->commit();
        $message = "Vente enregistrée avec succès ! Numéro : " . $numero;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erreur = $e->getMessage();
    }
}
/* LISTE DES PRODUITS */
$produitsDisponibles = $pdo->query("
    SELECT id, code_produit, nom_produit, type_emballage,
           quantite_par_emballage, quantite_stock,
           prix_vente, devise_vente
    FROM produits
    WHERE deleted_at IS NULL
    ORDER BY nom_produit ASC
")->fetchAll(PDO::FETCH_ASSOC);
/* HISTORIQUE DES VENTES */
$ventes = [];
try {
    $ventes = $pdo->query("
        SELECT id, numero, date_vente, montant_total, devise,
               mode_paiement, statut
        FROM ventes
        WHERE deleted_at IS NULL
        ORDER BY id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erreurListe = "Impossible de charger l'historique : " . $e->getMessage();
}
/* TOTAL DES VENTES DU JOUR */
$totalJour = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_total), 0)
        FROM ventes
        WHERE DATE(date_vente) = CURDATE()
          AND statut = 'Validée'
          AND devise = 'USD'
    ");
    $stmt->execute();
    $totalJour = (float)$stmt->fetchColumn();
} catch (Throwable $e) {
    $totalJour = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ventes | DINPE HOUSE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --or:#d4af37;
    --fond:#101010;
    --carte:#1b1b1b;
    --texte:#f5f5f5;
}
body {
    background:var(--fond);
    color:var(--texte);
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
.table thead {background:#292929;}
.table td,.table th {border-color:#383838;}
.ligne-produit {
    background:#151515;
    border:1px solid #383838;
    border-radius:10px;
    padding:15px;
    margin-bottom:12px;
}
.total-box {
    font-size:25px;
    color:var(--or);
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
    <a href="ventes.php" class="active"><i class="bi bi-cart-check me-2"></i> Ventes</a>
    <a href="depenses.php"><i class="bi bi-wallet2 me-2"></i> Dépenses</a>
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
            <h2><i class="bi bi-cart-check"></i> Gestion des ventes</h2>
            <p class="text-secondary mb-0">Enregistrement et suivi des ventes de boissons</p>
        </div>
        <div class="text-warning">
            <i class="bi bi-calendar3"></i>
            <?= date('d/m/Y') ?>
        </div>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($erreur): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card-custom">
                <small class="text-secondary">Ventes du jour en USD</small>
                <div class="total-box"><?= number_format($totalJour, 2) ?> $</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-custom">
                <small class="text-secondary">Taux de change utilisé</small>
                <div class="total-box"><?= number_format($taux, 0) ?> FC</div>
                <small class="text-secondary">Pour 1 USD</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-custom">
                <small class="text-secondary">Ventes enregistrées</small>
                <div class="total-box"><?= count($ventes) ?></div>
            </div>
        </div>
    </div>
    <div class="card-custom">
        <h4 class="mb-4"><i class="bi bi-plus-circle"></i> Nouvelle vente</h4>
        <form method="POST" id="formVente">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Date de vente</label>
                    <input type="date" name="date_vente" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Devise</label>
                    <select name="devise" class="form-select" required>
                        <option value="USD">Dollar américain ($)</option>
                        <option value="CDF">Franc congolais (FC)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mode de paiement</label>
                    <select name="mode_paiement" class="form-select" required>
                        <option value="Cash">Espèces</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Banque">Banque</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="taux_change" value="<?= htmlspecialchars((string)$taux) ?>">
            <hr class="border-secondary">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-warning">Produits vendus</h5>
                <button type="button" class="btn btn-outline-warning btn-sm" onclick="ajouterLigne()">
                    <i class="bi bi-plus-lg"></i> Ajouter un produit
                </button>
            </div>
            <div id="lignesProduits"></div>
            <div class="mb-3">
                <label class="form-label">Observation (facultatif)</label>
                <textarea name="observation" class="form-control" rows="2"></textarea>
            </div>
            <div class="text-end mb-3">
                <div class="text-secondary">Total de la vente</div>
                <div class="total-box"><span id="totalVente">0.00</span></div>
            </div>
            <button type="submit" name="enregistrer_vente" class="btn btn-gold btn-lg w-100">
                <i class="bi bi-check-circle"></i> Enregistrer la vente
            </button>
        </form>
    </div>
    <div class="card-custom">
        <h4 class="mb-3"><i class="bi bi-clock-history"></i> Historique des ventes</h4>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>N° Vente</th>
                        <th>Date</th>
                        <th>Montant</th>
                        <th>Devise</th>
                        <th>Paiement</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($ventes)): ?>
                    <tr><td colspan="6" class="text-center text-secondary">Aucune vente enregistrée.</td></tr>
                <?php else: ?>
                    <?php foreach ($ventes as $vente): ?>
                    <tr>
                        <td><?= htmlspecialchars($vente['numero']) ?></td>
                        <td><?= date('d/m/Y', strtotime($vente['date_vente'])) ?></td>
                        <td><?= number_format((float)$vente['montant_total'], 2) ?></td>
                        <td><?= htmlspecialchars($vente['devise']) ?></td>
                        <td><?= htmlspecialchars($vente['mode_paiement']) ?></td>
                        <td>
                            <span class="badge bg-success">
                                <?= htmlspecialchars($vente['statut']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
const produits = <?= json_encode($produitsDisponibles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
function ajouterLigne() {
    const conteneur = document.getElementById('lignesProduits');
    const ligne = document.createElement('div');
    ligne.className = 'ligne-produit';
    let options = '<option value="">Choisir un produit</option>';
    produits.forEach(p => {
        options += `<option value="${p.id}"
            data-stock="${p.quantite_stock}"
            data-carton="${p.quantite_par_emballage || 1}"
            data-prix="${p.prix_vente || 0}"
            data-devise="${p.devise_vente || 'USD'}">
            ${p.nom_produit} — Stock : ${p.quantite_stock}
        </option>`;
    });
    ligne.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Produit</label>
                <select name="id_produit[]" class="form-select produit-select" required>
                    ${options}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select name="emballage[]" class="form-select emballage">
                    <option value="Unité">Bouteille / Unité</option>
                    <option value="Carton">Carton</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Quantité</label>
                <input type="number" name="quantite[]" class="form-control quantite"
                       min="1" value="1" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Prix de vente</label>
                <input type="number" name="prix_unitaire[]" class="form-control prix"
                       min="0" step="0.01" value="0" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger w-100 supprimer">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <small class="text-secondary stock-info d-block mt-2"></small>
        <small class="text-warning sous-total d-block"></small>
    `;
    conteneur.appendChild(ligne);
    const select = ligne.querySelector('.produit-select');
    const emballage = ligne.querySelector('.emballage');
    const quantite = ligne.querySelector('.quantite');
    const prix = ligne.querySelector('.prix');
    function actualiserProduit() {
        const option = select.selectedOptions[0];
        if (!option || !option.value) return;
        const prixProduit = parseFloat(option.dataset.prix || 0);
        prix.value = prixProduit.toFixed(2);
        afficherStock();
        calculerTotal();
    }
    function afficherStock() {
        const option = select.selectedOptions[0];
        if (!option || !option.value) return;
        const stock = parseInt(option.dataset.stock || 0);
        const carton = parseInt(option.dataset.carton || 1);
        const unite = emballage.value === 'Carton' ? carton : 1;
        ligne.querySelector('.stock-info').textContent =
            `Stock disponible : ${stock} unités. ` +
            `Cette vente consommera ${unite} unité(s) par quantité saisie.`;
        quantite.max = Math.floor(stock / unite);
    }
    select.addEventListener('change', actualiserProduit);
    emballage.addEventListener('change', () => {
        afficherStock();
        calculerTotal();
    });
    quantite.addEventListener('input', calculerTotal);
    prix.addEventListener('input', calculerTotal);
    ligne.querySelector('.supprimer').addEventListener('click', () => {
        ligne.remove();
        calculerTotal();
    });
    actualiserProduit();
}
function calculerTotal() {
    let total = 0;
    document.querySelectorAll('.ligne-produit').forEach(ligne => {
        const quantite = parseFloat(ligne.querySelector('.quantite').value || 0);
        const prix = parseFloat(ligne.querySelector('.prix').value || 0);
        const sousTotal = quantite * prix;
        total += sousTotal;
        ligne.querySelector('.sous-total').textContent =
            'Sous-total : ' + sousTotal.toFixed(2);
    });
    document.getElementById('totalVente').textContent = total.toFixed(2);
}
document.getElementById('formVente').addEventListener('submit', function(e) {
    if (document.querySelectorAll('.ligne-produit').length === 0) {
        e.preventDefault();
        alert('Ajoutez au moins un produit.');
    }
});
ajouterLigne();
</script>
</body>
</html>