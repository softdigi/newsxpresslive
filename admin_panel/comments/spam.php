<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';

if (!in_array($_SESSION['admin']['role'], ['super_admin','admin','editor'])) exit('Access denied');

$stmt=$pdo->prepare("SELECT c.*, n.title FROM comments c LEFT JOIN news n ON c.news_id=n.id WHERE c.status='spam' ORDER BY c.created_at DESC");
$stmt->execute();
$data=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper">
<section class="content-header"><h1>Spam Comments</h1></section>
<section class="content">
<div id="spam-message" class="alert" style="display:none"></div>
<table class="table table-bordered" id="spam-table">
<thead>
<tr><th>Author</th><th>Comment</th><th>News</th><th>Date</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach($data as $row): ?>
<tr id="comment-row-<?= (int)$row['id'] ?>">
<td><?= htmlspecialchars($row['author_name']) ?></td>
<td><?= htmlspecialchars($row['content']) ?></td>
<td><?= htmlspecialchars($row['title']) ?></td>
<td><?= htmlspecialchars($row['created_at']) ?></td>
<td>
    <button
        class="btn btn-success btn-xs spam-action"
        data-id="<?= (int)$row['id'] ?>"
        data-action="approve"
        title="Approve comment">
        <i class="fa fa-check"></i> Approve
    </button>
    &nbsp;
    <button
        class="btn btn-danger btn-xs spam-action"
        data-id="<?= (int)$row['id'] ?>"
        data-action="delete"
        title="Delete comment"
        onclick="return confirmDelete(this)">
        <i class="fa fa-trash"></i> Delete
    </button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (empty($data)): ?>
<p class="text-muted">No spam comments at this time.</p>
<?php endif; ?>
</section>
</div>

<script>
(function () {
    'use strict';

    /**
     * Confirm before deleting a comment.
     * @param {HTMLButtonElement} btn
     * @returns {boolean} false to cancel the default click (handled via AJAX)
     */
    function confirmDelete(btn) {
        if (!window.confirm('Delete this comment permanently?')) {
            return false;
        }
        return true;
    }
    window.confirmDelete = confirmDelete;

    /**
     * Show a status message at the top of the section.
     * @param {string}  msg
     * @param {'success'|'danger'} type
     */
    function showMessage(msg, type) {
        var el = document.getElementById('spam-message');
        el.className  = 'alert alert-' + type;
        el.textContent = msg;
        el.style.display = 'block';
        setTimeout(function () { el.style.display = 'none'; }, 4000);
    }

    /**
     * Send an approve or delete request via fetch and remove the row on
     * success — no page reload required.
     * @param {number} commentId
     * @param {'approve'|'delete'} action
     */
    function handleCommentAction(commentId, action) {
        var url = '../api/comment_action.php';
        var csrfToken = document.querySelector('meta[name="csrf-token"]');

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken ? csrfToken.getAttribute('content') : ''
            },
            body: JSON.stringify({ comment_id: commentId, action: action })
        })
        .then(function (response) {
            if (!response.ok) {
                return response.json().then(function (err) {
                    throw new Error(err.message || 'Server error');
                });
            }
            return response.json();
        })
        .then(function (data) {
            if (data.success) {
                var row = document.getElementById('comment-row-' + commentId);
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity    = '0';
                    setTimeout(function () {
                        row.parentNode && row.parentNode.removeChild(row);
                        // Show empty message if no rows remain
                        var tbody = document.querySelector('#spam-table tbody');
                        if (tbody && tbody.rows.length === 0) {
                            var p = document.createElement('p');
                            p.className = 'text-muted';
                            p.textContent = 'No spam comments at this time.';
                            tbody.closest('.content').appendChild(p);
                        }
                    }, 300);
                }
                var verb = action === 'approve' ? 'approved' : 'deleted';
                showMessage('Comment ' + verb + ' successfully.', 'success');
            } else {
                showMessage(data.message || 'Action failed.', 'danger');
            }
        })
        .catch(function (err) {
            showMessage('Error: ' + err.message, 'danger');
        });
    }

    // Delegate click events on .spam-action buttons
    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.spam-action');
            if (!btn) return;

            var action    = btn.dataset.action;
            var commentId = parseInt(btn.dataset.id, 10);

            if (action === 'delete') {
                if (!window.confirm('Delete this comment permanently?')) return;
            }

            e.preventDefault();
            // Disable both buttons in this row while the request is in flight
            var row = document.getElementById('comment-row-' + commentId);
            if (row) {
                row.querySelectorAll('.spam-action').forEach(function (b) {
                    b.disabled = true;
                });
            }

            handleCommentAction(commentId, action);
        });
    });
}());
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
