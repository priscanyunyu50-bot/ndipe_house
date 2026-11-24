<?php
require_once 'auth.php';
require_once 'connexion.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$message = '';
$type_message = '';
/* =========================
   OUTILS DE DETECTION
========================= */
function colonnesTable(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}
function choisirColonne(array $colonnes, array $possibles): ?string {
    foreach ($possibles as $nom) {
        if (in_array($nom, $colonnes, true)) {
            return $nom;
        }
    }
    return null;
}
function nomSQL(?string $nom): string {
    return $nom ? "`" . str_replace("`", "``", $nom) . "`" : '';
}
function valeurParametre(PDO $pdo, string $table, ?string $colCle, ?string $colValeur, string $cle): string {
    if (!$colCle || !$colValeur) {
        return '';
    }
    try {
        $sql = "SELECT " . nomSQL($colValeur) .
               " FROM " . nomSQL($table) .
               " WHERE " . nomSQL($colCle) . " = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cle]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}
/* =========================
   STRUCTURE DES TABLES
========================= */
$colonnesParam = colonnesTable($pdo, 'parametres');
$colonnesTaux = colonnesTable($pdo, 'taux_change');
$cleParam = choisirColonne($colonnesParam, [
    'cle', 'nom_parametre', 'parametre', 'nom', 'code', 'key'
]);
$valeurParam = choisirColonne($colonnesParam, [
    'valeur', 'value', 'contenu', 'valeur_parametre'
]);
$colTaux = choisirColonne($colonnesTaux, [
    'taux', 'taux_change', 'valeur', 'rate'
]);
$colSource = choisirColonne($colonnesTaux, [
    'devise_source', 'devise_base', 'devise_depart', 'source'
]);
$colCible = choisirColonne($colonnesTaux, [
    'devise_cible', 'devise_destination', 'devise_arrivee', 'cible'
]);
$colDateTaux = choisirColonne($colonnesTaux, [
    'date_taux', 'date_application', 'date_enregistrement', 'created_at'
]);
/* =========================
   VALEURS ACTUELLES
========================= */
$nomEtablissement = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'nom_etablissement');
$telephone = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'telephone');
$adresse = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'adresse');
$email = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'email');
$tauxActuel = 0;
if ($colTaux) {
    try {
        $stmt = $pdo->query(
            "SELECT " . nomSQL($colTaux) .
            " FROM `taux_change` ORDER BY 1 DESC LIMIT 1"
        );
        $tauxActuel = (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $tauxActuel = 0;
    }
}
/* =========================
   ENREGISTRER PARAMETRES
========================= */
function enregistrerParametre(
    PDO $pdo,
    string $table,
    ?string $colCle,
    ?string $colValeur,
    string $cle,
    string $valeur
): void {
    if (!$colCle || !$colValeur) {
        throw new Exception("La structure de la table parametres n'est pas reconnue.");
    }
    $sql = "SELECT 1 FROM " . nomSQL($table) .
           " WHERE " . nomSQL($colCle) . " = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cle]);
    if ($stmt->fetchColumn()) {
        $sql = "UPDATE " . nomSQL($table) .
               " SET " . nomSQL($colValeur) . " = ?" .
               " WHERE " . nomSQL($colCle) . " = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$valeur, $cle]);
    } else {
        $sql = "INSERT INTO " . nomSQL($table) .
               " (" . nomSQL($colCle) . ", " . nomSQL($colValeur) . ")" .
               " VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cle, $valeur]);
    }
}
/* =========================
   TRAITEMENT DES FORMULAIRES
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['enregistrer_etablissement'])) {
            if (!$cleParam || !$valeurParam) {
                throw new Exception(
                    "Impossible de reconnaître les colonnes de la table parametres. Vérifie sa structure dans phpMyAdmin."
                );
            }
            $nom = trim($_POST['nom_etablissement'] ?? '');
            $tel = trim($_POST['telephone'] ?? '');
            $adr = trim($_POST['adresse'] ?? '');
            $mail = trim($_POST['email'] ?? '');
            if ($nom === '') {
                throw new Exception("Le nom de l'établissement est obligatoire.");
            }
            enregistrerParametre($pdo, 'parametres', $cleParam, $valeurParam, 'nom_etablissement', $nom);
            enregistrerParametre($pdo, 'parametres', $cleParam, $valeurParam, 'telephone', $tel);
            enregistrerParametre($pdo, 'parametres', $cleParam, $valeurParam, 'adresse', $adr);
            enregistrerParametre($pdo, 'parametres', $cleParam, $valeurParam, 'email', $mail);
            $message = "Les informations de l'établissement ont été enregistrées.";
            $type_message = "success";
        }
        if (isset($_POST['enregistrer_taux'])) {
            $nouveauTaux = (float)($_POST['taux_change'] ?? 0);
            if ($nouveauTaux <= 0) {
                throw new Exception("Veuillez saisir un taux de change supérieur à zéro.");
            }
            if (!$colTaux) {
                throw new Exception(
                    "La colonne du taux de change n'a pas été reconnue dans la table taux_change."
                );
            }
            $colonnesInsertion = [$colTaux];
            $valeursInsertion = [$nouveauTaux];
            if ($colSource) {
                $colonnesInsertion[] = $colSource;
                $valeursInsertion[] = 'USD';
            }
            if ($colCible) {
                $colonnesInsertion[] = $colCible;
                $valeursInsertion[] = 'CDF';
            }
            if ($colDateTaux) {
                $colonnesInsertion[] = $colDateTaux;
                $valeursInsertion[] = date('Y-m-d H:i:s');
            }
            $noms = array_map('nomSQL', $colonnesInsertion);
            $placeholders = array_fill(0, count($colonnesInsertion), '?');
            $sql = "INSERT INTO `taux_change` (" .
                   implode(', ', $noms) .
                   ") VALUES (" .
                   implode(', ', $placeholders) .
                   ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($valeursInsertion);
            $message = "Le nouveau taux de change a été enregistré.";
            $type_message = "success";
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $type_message = "danger";
    }
    /* Actualiser les valeurs après enregistrement */
    $nomEtablissement = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'nom_etablissement');
    $telephone = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'telephone');
    $adresse = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'adresse');
    $email = valeurParametre($pdo, 'parametres', $cleParam, $valeurParam, 'email');
    if ($colTaux) {
        try {
            $stmt = $pdo->query(
                "SELECT " . nomSQL($colTaux) .
                " FROM `taux_change` ORDER BY 1 DESC LIMIT 1"
            );
            $tauxActuel = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // On conserve la valeur précédente.
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paramètres | DINPE HOUSE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --fond: #101114;
    --sidebar: #17181d;
    --carte: #1b1c22;
    --or: #d6b45c;
    --texte: #f4f4f4;
    --secondaire: #a5a5a5;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    background: var(--fond);
    color: var(--texte);
    font-family: Arial, sans-serif;
}
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    background: var(--sidebar);
    padding: 25px 15px;
    overflow-y: auto;
    border-right: 1px solid #292a30;
}
.logo {
    text-align: center;
    color: var(--or);
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 35px;
}
.sidebar a {
    display: block;
    color: #c8c8c8;
    text-decoration: none;
    padding: 13px 15px;
    margin-bottom: 7px;
    border-radius: 9px;
    transition: .2s;
}
.sidebar a:hover,
.sidebar a.active {
    background: rgba(214,180,92,.13);
    color: var(--or);
}
.sidebar a i {
    margin-right: 10px;
}
.main {
    margin-left: 250px;
    padding: 30px;
}
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    gap: 15px;
}
.topbar h2 {
    font-size: 25px;
    font-weight: bold;
}
.topbar small {
    color: var(--secondaire);
}
.card-custom {
    background: var(--carte);
    border: 1px solid #2b2c32;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 25px;
}
.card-title {
    color: var(--or);
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 22px;
}
.form-label {
    color: #d0d0d0;
}
.form-control,
.form-select {
    background: #111217;
    border: 1px solid #363740;
    color: white;
    padding: 12px;
    border-radius: 9px;
}
.form-control:focus,
.form-select:focus {
    background: #111217;
    color: white;
    border-color: var(--or);
    box-shadow: 0 0 0 .2rem rgba(214,180,92,.12);
}
.form-control::placeholder {
    color: #777;
}
.btn-gold {
    background: var(--or);
    color: #171717;
    font-weight: bold;
    border: none;
    padding: 11px 20px;
    border-radius: 9px;
}
.btn-gold:hover {
    background: #edcb72;
    color: #111;
}
.currency-box {
    background: #111217;
    border: 1px solid #34353c;
    padding: 18px;
    border-radius: 12px;
    height: 100%;
}
.currency-symbol {
    font-size: 27px;
    color: var(--or);
    font-weight: bold;
}
.currency-name {
    color: var(--secondaire);
    font-size: 13px;
}
.rate-value {
    font-size: 25px;
    color: var(--or);
    font-weight: bold;
}
.alert {
    border-radius: 10px;
}
.note {
    color: var(--secondaire);
    font-size: 13px;
}
@media(max-width: 900px) {
    .sidebar {
        width: 75px;
        padding: 20px 8px;
    }
    .logo {
        font-size: 12px;
        writing-mode: vertical-rl;
        margin: auto auto 30px;
    }
    .sidebar a {
        text-align: center;
        font-size: 0;
        padding: 14px 5px;
    }
    .sidebar a i {
        font-size: 20px;
        margin: 0;
    }
    .main {
        margin-left: 75px;
        padding: 18px;
    }
}
@media(max-width: 550px) {
    .main {
        padding: 12px;
    }
    .topbar h2 {
        font-size: 20px;
    }
    .card-custom {
        padding: 17px;
    }
}
</style>
</head>
<body>
<!-- MENU LATERAL -->
<div class="sidebar">
    <div class="logo">DINPE HOUSE</div>
    <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Tableau de bord</a>
    <a href="produits.php"><i class="bi bi-box-seam"></i> Produits</a>
    <a href="approvisionnement.php"><i class="bi bi-truck"></i> Approvisionnement</a>
    <a href="ventes.php"><i class="bi bi-cart"></i> Ventes</a>
    <a href="depenses.php"><i class="bi bi-cash-stack"></i> Dépenses</a>
    <a href="entrees.php"><i class="bi bi-wallet2"></i> Autres entrées</a>
    <a href="rapports.php"><i class="bi bi-bar-chart"></i> Rapports</a>
    <a href="historique.php"><i class="bi bi-clock-history"></i> Historique</a>
    <a href="corbeille.php"><i class="bi bi-trash"></i> Corbeille</a>
    <a href="parametres.php" class="active"><i class="bi bi-gear"></i> Paramètres</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
</div>
<!-- CONTENU -->
<div class="main">
    <div class="topbar">
        <div>
            <h2><i class="bi bi-gear-fill" style="color:var(--or)"></i> Paramètres</h2>
            <small>Configuration générale de DINPE HOUSE</small>
        </div>
        <div>
            <span class="badge bg-warning text-dark p-2">
                Administration
            </span>
        </div>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($type_message) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <!-- INFORMATIONS ETABLISSEMENT -->
    <div class="card-custom">
        <div class="card-title">
            <i class="bi bi-building"></i> Informations de l'établissement
        </div>
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nom de l'établissement</label>
                    <input type="text"
                           name="nom_etablissement"
                           class="form-control"
                           value="<?= htmlspecialchars($nomEtablissement) ?>"
                           placeholder="DINPE HOUSE"
                           required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Téléphone</label>
                    <input type="text"
                           name="telephone"
                           class="form-control"
                           value="<?= htmlspecialchars($telephone) ?>"
                           placeholder="+243 ...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Adresse</label>
                    <input type="text"
                           name="adresse"
                           class="form-control"
                           value="<?= htmlspecialchars($adresse) ?>"
                           placeholder="Adresse de l'établissement">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Adresse e-mail</label>
                    <input type="email"
                           name="email"
                           class="form-control"
                           value="<?= htmlspecialchars($email) ?>"
                           placeholder="contact@example.com">
                </div>
            </div>
            <div class="mt-4">
                <button type="submit"
                        name="enregistrer_etablissement"
                        class="btn btn-gold">
                    <i class="bi bi-save"></i> Enregistrer les informations
                </button>
            </div>
        </form>
        <?php if (!$cleParam || !$valeurParam): ?>
            <p class="note mt-3">
                La table parametres ne présente pas de colonnes clé/valeur reconnues.
                Les informations ne pourront pas être enregistrées avant vérification de sa structure.
            </p>
        <?php endif; ?>
    </div>
    <!-- DEVISES -->
    <div class="card-custom">
        <div class="card-title">
            <i class="bi bi-currency-exchange"></i> Devises utilisées
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="currency-box">
                    <div class="currency-symbol">$</div>
                    <strong>Dollar américain</strong>
                    <div class="currency-name">USD — Devise étrangère</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="currency-box">
                    <div class="currency-symbol">FC</div>
                    <strong>Franc congolais</strong>
                    <div class="currency-name">CDF — Devise locale</div>
                </div>
            </div>
        </div>
        <p class="note mt-3">
            Les deux devises sont affichées comme devises de gestion.
            Le taux de change ci-dessous sert de référence pour la conversion.
        </p>
    </div>
    <!-- TAUX DE CHANGE -->
    <div class="card-custom">
        <div class="card-title">
            <i class="bi bi-graph-up-arrow"></i> Gestion du taux de change
        </div>
        <div class="row g-3 align-items-stretch">
            <div class="col-md-5">
                <div class="currency-box">
                    <div class="currency-name mb-2">Taux actuellement enregistré</div>
                    <div class="rate-value">
                        <?= $tauxActuel > 0
                            ? number_format($tauxActuel, 2, ',', ' ') . ' CDF'
                            : 'Non défini' ?>
                    </div>
                    <div class="note mt-2">Pour 1 USD</div>
                </div>
            </div>
            <div class="col-md-7">
                <form method="POST">
                    <label class="form-label">
                        Nouveau taux de change
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark text-warning border-secondary">
                            1 USD =
                        </span>
                        <input type="number"
                               name="taux_change"
                               class="form-control"
                               min="0.01"
                               step="0.01"
                               placeholder="Exemple : 2800"
                               required>
                        <span class="input-group-text bg-dark text-warning border-secondary">
                            CDF
                        </span>
                    </div>
                    <p class="note mt-2">
                        Saisis le nombre de francs congolais correspondant à un dollar américain.
                    </p>
                    <button type="submit"
                            name="enregistrer_taux"
                            class="btn btn-gold mt-2">
                        <i class="bi bi-arrow-repeat"></i> Enregistrer le taux
                    </button>
                </form>
            </div>
        </div>
        <?php if (!$colTaux): ?>
            <p class="note mt-3">
                La colonne contenant le taux n'a pas été reconnue dans la table taux_change.
                Une vérification de la structure est nécessaire.
            </p>
        <?php endif; ?>
    </div>
    <!-- INFORMATIONS SYSTEME -->
    <div class="card-custom">
        <div class="card-title">
            <i class="bi bi-shield-check"></i> Informations du système
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="currency-box">
                    <div class="currency-name">Application</div>
                    <strong>DINPE HOUSE</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="currency-box">
                    <div class="currency-name">Technologie</div>
                    <strong>PHP / MySQL</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="currency-box">
                    <div class="currency-name">Version PHP</div>
                    <strong><?= htmlspecialchars(PHP_VERSION) ?></strong>
                </div>
            </div>
        </div>
    </div>
    <div class="text-center note mt-4 mb-3">
        DINPE HOUSE © <?= date('Y') ?> — Gestion simplifiée de votre mini-bar.
    </div>
</div>
</body>
</html>