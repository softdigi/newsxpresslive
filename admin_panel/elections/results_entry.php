<?php
/**
 * admin_panel/elections/results_entry.php
 * Admin page — manage elections and enter/edit constituency results.
 *
 * Features:
 *   - List active elections
 *   - Add / edit constituency result rows
 *   - Bulk CSV upload
 *   - Recalculate party totals
 *   - Publish / Unpublish election
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../helpers/csrf.php';

if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['admin', 'super_admin', 'editor'], true)) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error   = '';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // ── Save / update a single constituency result ────────────────────────
    if ($action === 'save_result') {
        $election_id       = (int)($_POST['election_id'] ?? 0);
        $constituency_name = trim($_POST['constituency_name'] ?? '');
        $constituency_no   = trim($_POST['constituency_no'] ?? '');
        $district_id       = (int)($_POST['district_id'] ?? 0) ?: null;
        $winning_candidate = trim($_POST['winning_candidate'] ?? '');
        $winning_party     = trim($_POST['winning_party'] ?? '');
        $winning_party_short = strtoupper(trim($_POST['winning_party_short'] ?? ''));
        $winning_votes     = (int)($_POST['winning_votes'] ?? 0);
        $winning_margin    = (int)($_POST['winning_margin'] ?? 0);
        $runner_candidate  = trim($_POST['runner_candidate'] ?? '');
        $runner_party      = trim($_POST['runner_party'] ?? '');
        $runner_votes      = (int)($_POST['runner_votes'] ?? 0);
        $total_votes       = (int)($_POST['total_votes'] ?? 0);
        $voter_turnout     = (float)($_POST['voter_turnout'] ?? 0);
        $result_status     = in_array($_POST['result_status'] ?? '', ['leading','won','counting'], true)
                             ? $_POST['result_status'] : 'counting';

        if ($election_id > 0 && $constituency_name !== '') {
            $pdo->prepare(
                'INSERT INTO election_results
                   (election_id, constituency_name, constituency_no, district_id,
                    winning_candidate, winning_party, winning_party_short,
                    winning_votes, winning_margin, runner_candidate, runner_party,
                    runner_votes, total_votes, voter_turnout, result_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    winning_candidate=VALUES(winning_candidate),
                    winning_party=VALUES(winning_party),
                    winning_party_short=VALUES(winning_party_short),
                    winning_votes=VALUES(winning_votes),
                    winning_margin=VALUES(winning_margin),
                    runner_candidate=VALUES(runner_candidate),
                    runner_party=VALUES(runner_party),
                    runner_votes=VALUES(runner_votes),
                    total_votes=VALUES(total_votes),
                    voter_turnout=VALUES(voter_turnout),
                    result_status=VALUES(result_status)'
            )->execute([
                $election_id, $constituency_name, $constituency_no, $district_id,
                $winning_candidate, $winning_party, $winning_party_short,
                $winning_votes, $winning_margin, $runner_candidate, $runner_party,
                $runner_votes, $total_votes, $voter_turnout, $result_status,
            ]);
            $message = 'Result saved successfully.';
        } else {
            $error = 'Election and constituency name are required.';
        }
    }

    // ── Recalculate party totals ──────────────────────────────────────────
    if ($action === 'recalculate_totals') {
        $election_id = (int)($_POST['election_id'] ?? 0);
        if ($election_id > 0) {
            // Aggregate from election_results
            $stmt = $pdo->prepare(
                'SELECT winning_party AS party_name, winning_party_short AS party_short,
                        COUNT(*) AS seats_won,
                        SUM(winning_votes) AS total_votes
                 FROM election_results
                 WHERE election_id = ? AND result_status = \'won\'
                 GROUP BY winning_party_short, winning_party'
            );
            $stmt->execute([$election_id]);
            $parties = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grand_total = (int)$pdo->prepare(
                'SELECT COALESCE(SUM(total_votes),0) FROM election_results WHERE election_id = ?'
            )->execute([$election_id]) ? $pdo->query(
                "SELECT COALESCE(SUM(total_votes),0) FROM election_results WHERE election_id={$election_id}"
            )->fetchColumn() : 0;

            foreach ($parties as $p) {
                $vote_share = $grand_total > 0
                    ? round((float)$p['total_votes'] / (float)$grand_total * 100, 2)
                    : 0.0;
                $pdo->prepare(
                    'INSERT INTO election_party_summary
                       (election_id, party_name, party_short, seats_won, total_votes, vote_share)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       seats_won=VALUES(seats_won),
                       total_votes=VALUES(total_votes),
                       vote_share=VALUES(vote_share)'
                )->execute([
                    $election_id, $p['party_name'], $p['party_short'],
                    $p['seats_won'], $p['total_votes'], $vote_share,
                ]);
            }
            $message = 'Party totals recalculated.';
        }
    }

    // ── Publish / Unpublish election ──────────────────────────────────────
    if ($action === 'toggle_publish') {
        $election_id = (int)($_POST['election_id'] ?? 0);
        if ($election_id > 0) {
            $pdo->prepare('UPDATE elections SET is_active = 1 - is_active WHERE id = ?')
                ->execute([$election_id]);
            $message = 'Election status updated.';
        }
    }

    // ── Bulk CSV upload ───────────────────────────────────────────────────
    if ($action === 'csv_upload' && isset($_FILES['csv_file'])) {
        $election_id = (int)($_POST['election_id'] ?? 0);
        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK || $election_id <= 0) {
            $error = 'CSV upload failed or no election selected.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            $header = fgetcsv($handle); // skip header row
            $inserted = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 10) continue;
                [$cname, $cno, $wcandidate, $wparty, $wshort, $wvotes, $wmargin,
                 $rcandidate, $rparty, $rvotes] = $row;

                $pdo->prepare(
                    'INSERT INTO election_results
                       (election_id, constituency_name, constituency_no,
                        winning_candidate, winning_party, winning_party_short,
                        winning_votes, winning_margin, runner_candidate,
                        runner_party, runner_votes, result_status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,\'won\')
                     ON DUPLICATE KEY UPDATE
                       winning_candidate=VALUES(winning_candidate),
                       winning_party=VALUES(winning_party),
                       winning_party_short=VALUES(winning_party_short),
                       winning_votes=VALUES(winning_votes),
                       winning_margin=VALUES(winning_margin),
                       runner_candidate=VALUES(runner_candidate),
                       runner_party=VALUES(runner_party),
                       runner_votes=VALUES(runner_votes)'
                )->execute([
                    $election_id, trim($cname), trim($cno),
                    trim($wcandidate), trim($wparty), strtoupper(trim($wshort)),
                    (int)$wvotes, (int)$wmargin,
                    trim($rcandidate), trim($rparty), (int)$rvotes,
                ]);
                $inserted++;
            }
            fclose($handle);
            $message = "CSV imported: {$inserted} rows.";
        }
    }
}

// ── Fetch data for display ────────────────────────────────────────────────────
$elections = $pdo->query(
    'SELECT id, name, election_type, status, election_date, is_active FROM elections ORDER BY election_date DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$selected_election_id = (int)($_GET['election_id'] ?? ($_POST['election_id'] ?? 0));
$results = [];
$party_summary = [];
if ($selected_election_id > 0) {
    $results = $pdo->prepare(
        'SELECT * FROM election_results WHERE election_id = ? ORDER BY constituency_name'
    )->execute([$selected_election_id])
        ? $pdo->prepare('SELECT * FROM election_results WHERE election_id = ? ORDER BY constituency_name')
        : null;
    // Re-run properly
    $stmt = $pdo->prepare('SELECT * FROM election_results WHERE election_id = ? ORDER BY constituency_name');
    $stmt->execute([$selected_election_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare('SELECT * FROM election_party_summary WHERE election_id = ? ORDER BY seats_won DESC');
    $stmt2->execute([$selected_election_id]);
    $party_summary = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

$csrf_token = csrfToken();

require_once __DIR__ . '/../includes/header.php';
?>

<main>
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>🗳️ Election Results Manager</h2>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= htmlspecialchars($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <?= htmlspecialchars($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- ── Elections list ──────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header"><strong>All Elections</strong></div>
    <div class="card-body p-0">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th><th>Name</th><th>Type</th><th>Date</th>
            <th>Status</th><th>Active</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($elections as $e): ?>
          <tr>
            <td><?= $e['id'] ?></td>
            <td><?= htmlspecialchars($e['name']) ?></td>
            <td><?= htmlspecialchars($e['election_type']) ?></td>
            <td><?= htmlspecialchars($e['election_date']) ?></td>
            <td><span class="badge bg-info"><?= $e['status'] ?></span></td>
            <td>
              <?= $e['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?>
            </td>
            <td>
              <a href="?election_id=<?= $e['id'] ?>" class="btn btn-sm btn-primary">Manage</a>
              <form method="POST" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="toggle_publish">
                <input type="hidden" name="election_id" value="<?= $e['id'] ?>">
                <button class="btn btn-sm btn-<?= $e['is_active'] ? 'warning' : 'success' ?>"
                        onclick="return confirm('Toggle election status?')">
                  <?= $e['is_active'] ? 'Unpublish' : 'Publish' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($selected_election_id > 0): ?>

  <!-- ── Party summary ────────────────────────────────────────────────── -->
  <?php if ($party_summary): ?>
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between">
      <strong>Party Summary</strong>
      <form method="POST" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="recalculate_totals">
        <input type="hidden" name="election_id" value="<?= $selected_election_id ?>">
        <button class="btn btn-sm btn-warning">🔄 Recalculate Totals</button>
      </form>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead class="table-dark"><tr>
          <th>Party</th><th>Short</th><th>Won</th><th>Leading</th>
          <th>Total Votes</th><th>Vote Share</th>
        </tr></thead>
        <tbody>
          <?php foreach ($party_summary as $ps): ?>
          <tr>
            <td><?= htmlspecialchars($ps['party_name']) ?></td>
            <td>
              <span class="badge" style="background-color:<?= htmlspecialchars($ps['party_color']) ?>">
                <?= htmlspecialchars($ps['party_short']) ?>
              </span>
            </td>
            <td><?= number_format((int)$ps['seats_won']) ?></td>
            <td><?= number_format((int)$ps['seats_leading']) ?></td>
            <td><?= number_format((int)$ps['total_votes']) ?></td>
            <td><?= $ps['vote_share'] ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Add result form ──────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header"><strong>Add / Update Constituency Result</strong></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="save_result">
        <input type="hidden" name="election_id" value="<?= $selected_election_id ?>">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Constituency Name *</label>
            <input type="text" name="constituency_name" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Constituency No.</label>
            <input type="text" name="constituency_no" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Result Status</label>
            <select name="result_status" class="form-select">
              <option value="counting">Counting</option>
              <option value="leading">Leading</option>
              <option value="won">Won</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Voter Turnout (%)</label>
            <input type="number" name="voter_turnout" class="form-control" step="0.01" min="0" max="100">
          </div>
          <div class="col-md-2">
            <label class="form-label">Total Votes</label>
            <input type="number" name="total_votes" class="form-control" min="0">
          </div>

          <div class="col-12"><hr><strong>Winner</strong></div>
          <div class="col-md-3">
            <label class="form-label">Candidate</label>
            <input type="text" name="winning_candidate" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Party</label>
            <input type="text" name="winning_party" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Party Short</label>
            <input type="text" name="winning_party_short" class="form-control" maxlength="20">
          </div>
          <div class="col-md-2">
            <label class="form-label">Votes</label>
            <input type="number" name="winning_votes" class="form-control" min="0">
          </div>
          <div class="col-md-2">
            <label class="form-label">Margin</label>
            <input type="number" name="winning_margin" class="form-control" min="0">
          </div>

          <div class="col-12"><hr><strong>Runner Up</strong></div>
          <div class="col-md-3">
            <label class="form-label">Candidate</label>
            <input type="text" name="runner_candidate" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Party</label>
            <input type="text" name="runner_party" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Votes</label>
            <input type="number" name="runner_votes" class="form-control" min="0">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary">💾 Save Result</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── CSV Bulk Upload ───────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header"><strong>Bulk CSV Upload</strong></div>
    <div class="card-body">
      <p class="text-muted small mb-2">
        CSV columns (no header): constituency_name, constituency_no, winning_candidate,
        winning_party, winning_party_short, winning_votes, winning_margin,
        runner_candidate, runner_party, runner_votes
      </p>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="csv_upload">
        <input type="hidden" name="election_id" value="<?= $selected_election_id ?>">
        <div class="row g-2 align-items-end">
          <div class="col-md-6">
            <label class="form-label">CSV File</label>
            <input type="file" name="csv_file" accept=".csv" class="form-control" required>
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-success">📤 Upload CSV</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Existing constituency results ─────────────────────────────────── -->
  <?php if ($results): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Constituency Results (<?= count($results) ?>)</strong></div>
    <div class="card-body p-0" style="overflow-x:auto">
      <table class="table table-sm table-striped mb-0">
        <thead class="table-dark">
          <tr>
            <th>Constituency</th><th>Winner</th><th>Party</th>
            <th>Votes</th><th>Margin</th><th>Runner</th>
            <th>Status</th><th>Updated</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['constituency_name']) ?>
                <?php if ($r['constituency_no']): ?>
                  <small class="text-muted">(<?= htmlspecialchars($r['constituency_no']) ?>)</small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)$r['winning_candidate']) ?></td>
            <td><?= htmlspecialchars((string)$r['winning_party_short']) ?></td>
            <td><?= number_format((int)$r['winning_votes']) ?></td>
            <td><?= number_format((int)$r['winning_margin']) ?></td>
            <td><?= htmlspecialchars((string)$r['runner_candidate']) ?></td>
            <td>
              <span class="badge bg-<?= $r['result_status'] === 'won' ? 'success' : ($r['result_status'] === 'leading' ? 'warning' : 'secondary') ?>">
                <?= $r['result_status'] ?>
              </span>
            </td>
            <td><small><?= $r['updated_at'] ?></small></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <div class="alert alert-info">Select an election from the table above to manage its results.</div>
  <?php endif; ?>

</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
