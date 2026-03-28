<?php
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, agency_id FROM admin_users WHERE id = :id AND role = 'reporter'");
$stmt->execute([':id' => $id]);
$reporter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporter) {
    header('Location: index.php');
    exit;
}

// Fetch available agencies
$agencies_stmt = $pdo->prepare("SELECT id, name FROM admin_users WHERE role = 'agency' AND status = 'active' ORDER BY name ASC");
$agencies_stmt->execute();
$agencies = $agencies_stmt->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $new_agency_id = (int) ($_POST['new_agency_id'] ?? 0);

    if ($new_agency_id <= 0) {
        $error = 'Please select a valid agency.';
    } elseif ($new_agency_id === (int) $reporter['agency_id']) {
        $error = 'Reporter is already assigned to this agency.';
    } else {
        // Verify agency exists
        $check = $pdo->prepare("SELECT id FROM admin_users WHERE id = :id AND role = 'agency'");
        $check->execute([':id' => $new_agency_id]);
        if (!$check->fetch()) {
            $error = 'Selected agency does not exist.';
        } else {
            $upd = $pdo->prepare("UPDATE admin_users SET agency_id = :agency_id WHERE id = :id AND role = 'reporter'");
            $upd->execute([':agency_id' => $new_agency_id, ':id' => $id]);
            $success = 'Reporter transferred to new agency successfully.';
            $reporter['agency_id'] = $new_agency_id;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transfer Agency</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}
        .container{max-width:550px;margin:40px auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h1{color:#333;margin-bottom:20px}
        .info{background:#f8f9fa;padding:15px;border-radius:6px;margin-bottom:20px}
        label{display:block;margin-bottom:5px;font-weight:bold;font-size:14px;color:#555}
        select{width:100%;padding:10px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px;font-size:14px}
        .btn{display:inline-block;padding:10px 20px;border:none;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff;margin-right:8px}
        .btn-primary{background:#007bff}.btn-secondary{background:#6c757d}
        .alert-success{background:#d4edda;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px}
        .alert-danger{background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;margin-bottom:15px}
    </style>
</head>
<body>
<div class="container">
    <h1>Transfer Agency</h1>

    <?php if ($success): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="info">
        <strong>Reporter:</strong> <?php echo htmlspecialchars($reporter['name']); ?><br>
        <strong>Email:</strong> <?php echo htmlspecialchars($reporter['email']); ?><br>
        <strong>Current Agency ID:</strong> <?php echo (int)$reporter['agency_id']; ?>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$reporter['id']; ?>">

        <label>Transfer to Agency</label>
        <select name="new_agency_id" required>
            <option value="">-- Select Agency --</option>
            <?php foreach ($agencies as $a): ?>
                <option value="<?php echo (int)$a['id']; ?>" <?php echo (int)$a['id'] === (int)$reporter['agency_id'] ? 'disabled' : ''; ?>>
                    <?php echo htmlspecialchars($a['name']); ?> (ID: <?php echo (int)$a['id']; ?>)
                    <?php echo (int)$a['id'] === (int)$reporter['agency_id'] ? ' [CURRENT]' : ''; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-primary">Transfer</button>
        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>
