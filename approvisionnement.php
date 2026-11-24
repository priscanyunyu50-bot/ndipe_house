<?php
require_once "auth.php";
require_once "connexion.php";
$message = "";
$type_message = "success";
function e($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, 'UTF-8');
}
// RECUPERATION DES PRODUITS
$produits = $pdo->query("
    SELECT id, code_produit, nom_produit, unite,
           type_emballage, quantite_par_emballage,
           prix_achat, devise_achat
    FROM produits
    WHERE deleted_at IS NULL
    ORDER BY nom_produit ASC
")->fetchAll(PDO::FETCH_ASSOC);
// RECUPERATION DES FOURNISSEURS
$fournisseurs = $pdo->query("
    SELECT id, nom
    FROM fournisseurs
    WHERE deleted_at IS NULL
    ORDER BY nom ASC
")->fetchAll(PDO::FETCH_ASSOC);
// ENREGISTREMENT D'UN APPROVISIONNEMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer'])) {
    $fournisseur = !empty($_POST['id_fournisseur'])
        ? (int)$_POST['id_fournisseur']
        : null;
    $date = $_POST['date_approvisionnement'] ?? date('Y-m-d');
    $devise = $_POST['devise'] ?? 'USD';
    $taux = (float)($_POST['taux_change'] ?? 1);
    $observation = trim($_POST['observation'] ?? '');
    $ids = $_POST['id_produit'] ?? [];
    $emballages = $_POST['nombre_emballages'] ?? [];
    $unites = $_POST['unite_emballage'] ?? [];
    $quantites = $_POST['quantite_unitaire'] ?? [];
    $prix = $_POST['prix_emballage'] ?? [];
    try {
        if (!in_array($devise, ['USD', 'CDF'], true)) {
            throw new Exception("Devise invalide.");
        }
        if ($taux <= 0) {
            throw new Exception("Le taux de change doit être supérieur à zéro.");
        }
        if ($devise === 'USD') {
            $taux = 1;
        }
        if ($date === '') {
            throw new Exception("Veuillez sélectionner une date.");
        }
        if (empty($ids) || !is_array($ids)) {
            throw new Exception("Ajoutez au moins un produit.");
        }
        if ($fournisseur !== null) {
            $check = $pdo->prepare("
                SELECT id FROM fournisseurs
                WHERE id = ? AND deleted_at IS NULL
            ");
            $check->execute([$fournisseur]);
            if (!$check->fetch()) {
                throw new Exception("Fournisseur introuvable.");
            }
        }
        $lignes = [];
        $montant_total = 0;
        foreach ($ids as $i => $id_produit) {
            $id_produit = (int)$id_produit;
            $nb_emballages = (int)($emballages[$i] ?? 0);
            $unite_emballage = trim($unites[$i] ?? '');
            $quantite_unitaire = (int)($quantites[$i] ?? 0);
            $prix_emballage = (float)($prix[$i] ?? 0);
            if (
                $id_produit <= 0 ||
                $nb_emballages <= 0 ||
                $quantite_unitaire <= 0 ||
                $unite_emballage === '' ||
                $prix_emballage < 0
            ) {
                throw new Exception("Vérifiez les informations de chaque produit.");
            }
            $check = $pdo->prepare("
                SELECT id, nom_produit
                FROM produits
                WHERE id = ? AND deleted_at IS NULL
            ");
            $check->execute([$id_produit]);
            $produit = $check->fetch(PDO::FETCH_ASSOC);
            if (!$produit) {
                throw new Exception("Un produit sélectionné n'existe plus.");
            }
            // CALCUL DES UNITES
            $quantite_totale = $nb_emballages * $quantite_unitaire;
            // PRIX D'ACHAT D'UNE UNITE
            $prix_unitaire = $prix_emballage / $quantite_unitaire;
            // MONTANT DE LA LIGNE
            $sous_total = $nb_emballages * $prix_emballage;
            $montant_total += $sous_total;
            $lignes[] = [
                'id_produit' => $id_produit,
                'nom' => $produit['nom_produit'],
                'nombre_emballages' => $nb_emballages,
                'unite_emballage' => $unite_emballage,
                'quantite_unitaire' => $quantite_unitaire,
                'quantite_totale' => $quantite_totale,
                'prix_unitaire' => $prix_unitaire,
                'sous_total' => $sous_total
            ];
        }
        if ($montant_total <= 0) {
            throw new Exception("Le montant total doit être supérieur à zéro.");
        }
        $pdo->beginTransaction();
        // NUMERO AUTOMATIQUE
        $numero = 'APP-' . date('Ymd-His') . '-' . random_int(100, 999);
        // CREATION DE L'APPROVISIONNEMENT
        $sql = $pdo->prepare("
            INSERT INTO approvisionnements (
                numero,
                id_fournisseur,
                date_approvisionnement,
                montant_total,
                devise,
                taux_change,
                observation,
                statut
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Valide')
        ");
        $sql->execute([
            $numero,
            $fournisseur,
            $date,
            $montant_total,
            $devise,
            $taux,
            $observation
        ]);
        $id_approvisionnement = $pdo->lastInsertId();
        // ENREGISTREMENT DES DETAILS ET AUGMENTATION DU STOCK
        foreach ($lignes as $ligne) {
            $detail = $pdo->prepare("
                INSERT INTO details_approvisionnement (
                    id_approvisionnement,
                    id_produit,
                    quantite,
                    prix_unitaire,
                    sous_total,
                    nombre_emballages,
                    unite_emballage,
                    quantite_unitaire
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $detail->execute([
                $id_approvisionnement,
                $ligne['id_produit'],
                $ligne['quantite_totale'],
                $ligne['prix_unitaire'],
                $ligne['sous_total'],
                $ligne['nombre_emballages'],
                $ligne['unite_emballage'],
                $ligne['quantite_unitaire']
            ]);
            // AUGMENTATION AUTOMATIQUE DU STOCK
            $stock = $pdo->prepare("
                UPDATE produits
                SET quantite_stock = quantite_stock + ?,
                    prix_achat = ?,
                    devise_achat = ?
                WHERE id = ?
                AND deleted_at IS NULL
            ");
            $stock->execute([
                $ligne['quantite_totale'],
                $ligne['prix_unitaire'],
                $devise,
                $ligne['id_produit']
            ]);
        }
        $pdo->commit();
        header("Location: approvisionnement.php?success=1");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Erreur : " . $e->getMessage();
        $type_message = "danger";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Erreur de base de données : " . $e->getMessage();
        $type_message = "danger";
    }
}
if (isset($_GET['success'])) {
    $message = "Approvisionnement enregistré ! Le stock a été actualisé.";
}
// HISTORIQUE DES APPROVISIONNEMENTS
$historique = $pdo->query("
    SELECT
        a.*,
        f.nom AS nom_fournisseur
    FROM approvisionnements a
    LEFT JOIN fournisseurs f ON a.id_fournisseur = f.id
    WHERE a.deleted_at IS NULL
    ORDER BY a.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
// STATISTIQUES
$total_approvisionnements = $pdo->query("
    SELECT COUNT(*)
    FROM approvisionnements
    WHERE deleted_at IS NULL AND statut = 'Valide'
")->fetchColumn();
$total_achats_usd = $pdo->query("
    SELECT COALESCE(SUM(
        CASE
            WHEN devise = 'USD' THEN montant_total
            ELSE montant_total / NULLIF(taux_change, 0)
        END
    ), 0)
    FROM approvisionnements
    WHERE deleted_at IS NULL AND statut = 'Valide'
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approvisionnements | DINPE HOUSE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    background: #101114;
    color: #f5f5f5;
    font-family: Arial, sans-serif;
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
    color: white;
}
.total-box {
    background: #292a30;
    border: 1px solid #d6b45c;
    border-radius: 10px;
    padding: 18px;
}
.total-box h3 {
    color: #d6b45c;
    font-weight: bold;
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
<!-- MENU -->
<div class="sidebar">
<div class="brand">DINPE HOUSE</div>
<a href="dashboard.php">
    <i class="bi bi-speedometer2 me-2"></i> Tableau de bord
</a>
<a href="produits.php">
    <i class="bi bi-box-seam me-2"></i> Produits
</a>
<a href="approvisionnement.php" class="active">
    <i class="bi bi-truck me-2"></i> Approvisionnements
</a>
<a href="ventes.php">
    <i class="bi bi-cart-check me-2"></i> Ventes
</a>
<a href="depenses.php">
    <i class="bi bi-wallet2 me-2"></i> Dépenses
</a>
<a href="entrees.php">
    <i class="bi bi-cash-stack me-2"></i> Autres entrées
</a>
<a href="rapports.php">
    <i class="bi bi-bar-chart me-2"></i> Rapports
</a>
<a href="historique.php">
    <i class="bi bi-clock-history me-2"></i> Historique
</a>
<a href="corbeille.php">
    <i class="bi bi-trash me-2"></i> Corbeille
</a>
<a href="parametres.php">
    <i class="bi bi-gear me-2"></i> Paramètres
</a>
<a href="logout.php">
    <i class="bi bi-box-arrow-right me-2"></i> Déconnexion
</a>
</div>
<!-- CONTENU -->
<div class="main">
<div class="mb-4">
    <h2>Gestion des approvisionnements</h2>
    <p class="text-secondary">
        Enregistrement des achats et actualisation automatique du stock.
    </p>
</div>
<?php if ($message): ?>
    <div class="alert alert-<?= e($type_message) ?> alert-dismissible fade show">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<!-- STATISTIQUES -->
<div class="row">
    <div class="col-md-6">
        <div class="card-box">
            <div class="text-secondary">Approvisionnements validés</div>
            <div class="stat-number">
                <?= (int)$total_approvisionnements ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-box">
            <div class="text-secondary">Valeur totale des achats en USD équivalent</div>
            <div class="stat-number">
                $ <?= number_format((float)$total_achats_usd, 2, ',', ' ') ?>
            </div>
        </div>
    </div>
</div>
<!-- FORMULAIRE -->
<div class="card-box">
    <h4 class="mb-4">
        <i class="bi bi-plus-circle me-2"></i>
        Nouvel approvisionnement
    </h4>
    <form method="POST" id="formApprovisionnement">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label">Fournisseur</label>
                <select name="id_fournisseur" class="form-select">
                    <option value="">Fournisseur non précisé</option>
                    <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= (int)$f['id'] ?>">
                            <?= e($f['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Date d'approvisionnement</label>
                <input
                    type="date"
                    name="date_approvisionnement"
                    class="form-control"
                    value="<?= date('Y-m-d') ?>"
                    required
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">Devise de paiement</label>
                <select name="devise" id="devise" class="form-select">
                    <option value="USD">Dollar américain ($)</option>
                    <option value="CDF">Franc congolais (FC)</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Taux de change (1 USD = ? FC)</label>
                <input
                    type="number"
                    name="taux_change"
                    id="taux_change"
                    class="form-control"
                    min="1"
                    step="0.01"
                    value="2800"
                    required
                >
            </div>
            <div class="col-md-8">
                <label class="form-label">Observation</label>
                <input
                    type="text"
                    name="observation"
                    class="form-control"
                    placeholder="Remarque sur cet approvisionnement..."
                >
            </div>
        </div>
        <hr class="border-secondary">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>Produits achetés</h4>
            <button type="button" class="btn btn-gold btn-sm" onclick="ajouterLigne()">
                <i class="bi bi-plus-circle"></i> Ajouter un produit
            </button>
        </div>
        <div class="table-responsive">
            <table class="table" id="tableProduits">
                <thead>
                    <tr>
                        <th style="min-width:180px">Produit</th>
                        <th>Emballage</th>
                        <th>Nombre</th>
                        <th>Unités / emballage</th>
                        <th>Prix / emballage</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="corpsProduits">
                </tbody>
            </table>
        </div>
        <div class="row justify-content-end mt-4">
            <div class="col-md-5">
                <div class="total-box">
                    <div class="text-secondary">
                        Montant total de l'approvisionnement
                    </div>
                    <h3 id="montantTotal">$ 0.00</h3>
                </div>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" name="enregistrer" class="btn btn-gold px-4">
                <i class="bi bi-save me-2"></i>
                Enregistrer l'approvisionnement
            </button>
        </div>
    </form>
</div>
<!-- HISTORIQUE -->
<div class="card-box">
    <h4 class="mb-4">Historique des approvisionnements</h4>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Numéro</th>
                    <th>Date</th>
                    <th>Fournisseur</th>
                    <th>Montant</th>
                    <th>Devise</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($historique): ?>
                <?php foreach ($historique as $h): ?>
                    <tr>
                        <td><?= e($h['numero']) ?></td>
                        <td><?= e(date('d/m/Y', strtotime($h['date_approvisionnement']))) ?></td>
                        <td><?= e($h['nom_fournisseur'] ?? 'Non précisé') ?></td>
                        <td><?= number_format((float)$h['montant_total'], 2, ',', ' ') ?></td>
                        <td><?= e($h['devise']) ?></td>
                        <td>
                            <span class="badge bg-success">
                                <?= e($h['statut']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center text-secondary">
                        Aucun approvisionnement enregistré.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<!-- DONNEES PRODUITS POUR JAVASCRIPT -->
<script>
const produitsDisponibles = <?= json_encode($produits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let compteur = 0;
// AJOUTER UNE LIGNE
function ajouterLigne() {
    const tbody = document.getElementById('corpsProduits');
    const ligne = document.createElement('tr');
    ligne.dataset.index = compteur;
    let options = '<option value="">Choisir un produit</option>';
    produitsDisponibles.forEach(p => {
        options += `
            <option value="${p.id}"
                data-emballage="${p.type_emballage || 'Casier'}"
                data-unites="${p.quantite_par_emballage || 1}"
                data-prix="${p.prix_achat || 0}">
                ${p.nom_produit}
            </option>
        `;
    });
    ligne.innerHTML = `
        <td>
            <select name="id_produit[]" class="form-select produit-select" required>
                ${options}
            </select>
        </td>
        <td>
            <input type="text"
                name="unite_emballage[]"
                class="form-control emballage"
                value="Casier"
                required>
        </td>
        <td>
            <input type="number"
                name="nombre_emballages[]"
                class="form-control nombre"
                min="1"
                value="1"
                required>
        </td>
        <td>
            <input type="number"
                name="quantite_unitaire[]"
                class="form-control unites"
                min="1"
                value="20"
                required>
        </td>
        <td>
            <input type="number"
                name="prix_emballage[]"
                class="form-control prix"
                min="0"
                step="0.01"
                value="0"
                required>
        </td>
        <td class="sous-total text-warning fw-bold">
            0.00
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm supprimer">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(ligne);
    compteur++;
    activerCalculs(ligne);
}
// CALCULS AUTOMATIQUES
function activerCalculs(ligne) {
    const select = ligne.querySelector('.produit-select');
    const emballage = ligne.querySelector('.emballage');
    const unites = ligne.querySelector('.unites');
    const prix = ligne.querySelector('.prix');
    const nombre = ligne.querySelector('.nombre');
    select.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (!this.value) return;
        emballage.value = option.dataset.emballage || 'Casier';
        unites.value = option.dataset.unites || 1;
        prix.value = parseFloat(option.dataset.prix || 0).toFixed(2);
        calculer();
    });
    [nombre, unites, prix].forEach(input => {
        input.addEventListener('input', calculer);
    });
    ligne.querySelector('.supprimer').addEventListener('click', function() {
        ligne.remove();
        calculer();
    });
}
// CALCUL DU TOTAL GENERAL
function calculer() {
    let total = 0;
    document.querySelectorAll('#corpsProduits tr').forEach(ligne => {
        const nombre = parseFloat(ligne.querySelector('.nombre').value) || 0;
        const unites = parseFloat(ligne.querySelector('.unites').value) || 0;
        const prix = parseFloat(ligne.querySelector('.prix').value) || 0;
        const sousTotal = nombre * prix;
        ligne.querySelector('.sous-total').textContent = sousTotal.toFixed(2);
        total += sousTotal;
    });
    const devise = document.getElementById('devise').value;
    document.getElementById('montantTotal').textContent =
        (devise === 'USD' ? '$ ' : 'FC ') +
        total.toLocaleString('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
}
// GESTION DU TAUX DE CHANGE
document.getElementById('devise').addEventListener('change', function() {
    const taux = document.getElementById('taux_change');
    if (this.value === 'USD') {
        taux.value = 1;
        taux.readOnly = true;
    } else {
        taux.value = 2800;
        taux.readOnly = false;
    }
});
// VERIFICATION DU FORMULAIRE
document.getElementById('formApprovisionnement').addEventListener('submit', function(event) {
    const lignes = document.querySelectorAll('#corpsProduits tr');
    if (lignes.length === 0) {
        event.preventDefault();
        alert('Veuillez ajouter au moins un produit.');
        return;
    }
    for (const ligne of lignes) {
        if (
            !ligne.querySelector('.produit-select').value ||
            Number(ligne.querySelector('.nombre').value) <= 0 ||
            Number(ligne.querySelector('.unites').value) <= 0 ||
            Number(ligne.querySelector('.prix').value) < 0
        ) {
            event.preventDefault();
            alert('Veuillez vérifier les informations des produits.');
            return;
        }
    }
});
// PREMIERE LIGNE AUTOMATIQUE
ajouterLigne();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>