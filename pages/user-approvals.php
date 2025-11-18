<?php
/**
 * User Approvals Page - Admin Only
 * Manage pending user registrations
 */

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

// Only super_admin and admin can manage approvals
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    redirect('index.php?page=dashboard');
}

$message = '';
$message_type = 'success';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza. Riprova.';
        $message_type = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $user_id = $_POST['user_id'] ?? null;

        if ($user_id && in_array($action, ['approve', 'reject'])) {
            try {
                if ($action === 'approve') {
                    // Approve user
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET status = 'approved',
                            attivo = TRUE,
                            approved_by = ?,
                            approved_at = NOW()
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$_SESSION['user_id'], $user_id]);

                    if ($stmt->rowCount() > 0) {
                        // Log approval
                        $stmt = $pdo->prepare("
                            INSERT INTO user_approval_log
                            (user_id, action, performed_by, ip_address, user_agent)
                            VALUES (?, 'approved', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $user_id,
                            $_SESSION['user_id'],
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);

                        // Get user email for notification
                        $stmt = $pdo->prepare("SELECT email, nome, cognome FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $user = $stmt->fetch();

                        logSecurityEvent($pdo, 'user_approved', "User approved: {$user['email']}", [
                            'user_id' => $user_id,
                            'approved_by' => $_SESSION['user_id']
                        ], 'info');

                        $message = "Utente {$user['nome']} {$user['cognome']} approvato con successo!";
                        $message_type = 'success';

                        // TODO: Send approval email to user
                    } else {
                        $message = 'Utente non trovato o già approvato.';
                        $message_type = 'warning';
                    }

                } elseif ($action === 'reject') {
                    $reason = $_POST['rejection_reason'] ?? 'Nessuna motivazione fornita';

                    // Reject user
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET status = 'rejected',
                            attivo = FALSE,
                            approved_by = ?,
                            approved_at = NOW(),
                            rejection_reason = ?
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$_SESSION['user_id'], $reason, $user_id]);

                    if ($stmt->rowCount() > 0) {
                        // Log rejection
                        $stmt = $pdo->prepare("
                            INSERT INTO user_approval_log
                            (user_id, action, performed_by, reason, ip_address, user_agent)
                            VALUES (?, 'rejected', ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $user_id,
                            $_SESSION['user_id'],
                            $reason,
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);

                        // Get user email
                        $stmt = $pdo->prepare("SELECT email, nome, cognome FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $user = $stmt->fetch();

                        logSecurityEvent($pdo, 'user_rejected', "User rejected: {$user['email']}", [
                            'user_id' => $user_id,
                            'rejected_by' => $_SESSION['user_id'],
                            'reason' => $reason
                        ], 'warning');

                        $message = "Utente {$user['nome']} {$user['cognome']} rifiutato.";
                        $message_type = 'warning';

                        // TODO: Send rejection email to user
                    } else {
                        $message = 'Utente non trovato o già processato.';
                        $message_type = 'warning';
                    }
                }
            } catch (PDOException $e) {
                error_log("User approval error: " . $e->getMessage());
                $message = 'Errore durante l\'operazione. Riprova più tardi.';
                $message_type = 'danger';
            }
        }
    }
}

// Get statistics
$stats = $pdo->query("
    SELECT
        COUNT(*) as total_users,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
    FROM users
")->fetch();

// Get pending users
$pending_users = $pdo->query("
    SELECT u.*,
           pr.ip_address,
           pr.created_at as registration_date,
           DATEDIFF(NOW(), u.created_at) as days_waiting
    FROM users u
    LEFT JOIN pending_registrations pr ON u.id = pr.user_id
    WHERE u.status = 'pending'
    ORDER BY u.created_at DESC
")->fetchAll();

// Get recently processed users (last 30 days)
$recent_users = $pdo->query("
    SELECT u.*,
           u2.username as approved_by_username,
           CONCAT(u2.nome, ' ', u2.cognome) as approved_by_name
    FROM users u
    LEFT JOIN users u2 ON u.approved_by = u2.id
    WHERE u.status IN ('approved', 'rejected')
      AND u.approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY u.approved_at DESC
    LIMIT 20
")->fetchAll();

?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="h3 mb-1">
                <i class="bi bi-person-check text-primary"></i> Gestione Approvazioni Utenti
            </h1>
            <p class="text-muted mb-0">Approva o rifiuta nuove registrazioni</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">In Attesa</p>
                            <h3 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h3>
                        </div>
                        <div class="text-warning">
                            <i class="bi bi-hourglass-split fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Approvati</p>
                            <h3 class="mb-0 text-success"><?php echo $stats['approved']; ?></h3>
                        </div>
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Rifiutati</p>
                            <h3 class="mb-0 text-danger"><?php echo $stats['rejected']; ?></h3>
                        </div>
                        <div class="text-danger">
                            <i class="bi bi-x-circle-fill fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-secondary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Sospesi</p>
                            <h3 class="mb-0 text-secondary"><?php echo $stats['suspended']; ?></h3>
                        </div>
                        <div class="text-secondary">
                            <i class="bi bi-pause-circle-fill fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Users -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-warning bg-opacity-10">
                    <h5 class="mb-0">
                        <i class="bi bi-hourglass-split"></i> Utenti in Attesa di Approvazione
                        <?php if (count($pending_users) > 0): ?>
                            <span class="badge bg-warning"><?php echo count($pending_users); ?></span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_users)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted"></i>
                            <h5 class="mt-3 text-muted">Nessuna richiesta in attesa</h5>
                            <p class="text-muted">Tutte le registrazioni sono state processate.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Utente</th>
                                        <th>Email</th>
                                        <th>Associazione</th>
                                        <th>Registrato il</th>
                                        <th>In attesa da</th>
                                        <th>IP</th>
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_users as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle bg-warning bg-opacity-25 text-warning me-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: bold;">
                                                        <?php echo strtoupper(substr($user['nome'], 0, 1) . substr($user['cognome'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></strong>
                                                        <?php if ($user['telefono']): ?>
                                                            <br><small class="text-muted"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($user['telefono']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['nome_associazione'] ?? '-'); ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('d/m/Y H:i', strtotime($user['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['days_waiting'] > 7 ? 'danger' : ($user['days_waiting'] > 3 ? 'warning' : 'info'); ?>">
                                                    <?php echo $user['days_waiting']; ?> giorni
                                                </span>
                                            </td>
                                            <td><small class="text-muted font-monospace"><?php echo htmlspecialchars($user['ip_address'] ?? '-'); ?></small></td>
                                            <td class="text-end">
                                                <div class="btn-group">
                                                    <!-- Approve Button -->
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success" title="Approva"
                                                                onclick="return confirm('Approvare questo utente?');">
                                                            <i class="bi bi-check-lg"></i> Approva
                                                        </button>
                                                    </form>

                                                    <!-- Reject Button with Modal -->
                                                    <button type="button" class="btn btn-sm btn-danger" title="Rifiuta"
                                                            data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $user['id']; ?>">
                                                        <i class="bi bi-x-lg"></i> Rifiuta
                                                    </button>

                                                    <!-- View Details -->
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Dettagli"
                                                            data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $user['id']; ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>

                                                <!-- Reject Modal -->
                                                <div class="modal fade" id="rejectModal<?php echo $user['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Rifiuta Registrazione</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                                    <input type="hidden" name="action" value="reject">
                                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">

                                                                    <p>Stai per rifiutare la registrazione di:</p>
                                                                    <p><strong><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></strong><br>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small></p>

                                                                    <div class="mb-3">
                                                                        <label for="rejection_reason<?php echo $user['id']; ?>" class="form-label">Motivazione (opzionale)</label>
                                                                        <textarea class="form-control" id="rejection_reason<?php echo $user['id']; ?>"
                                                                                  name="rejection_reason" rows="3"
                                                                                  placeholder="Specifica il motivo del rifiuto..."></textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                                                    <button type="submit" class="btn btn-danger">
                                                                        <i class="bi bi-x-lg"></i> Conferma Rifiuto
                                                                    </button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Details Modal -->
                                                <div class="modal fade" id="detailsModal<?php echo $user['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Dettagli Registrazione</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <strong>Nome Completo:</strong><br>
                                                                        <?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <strong>Email:</strong><br>
                                                                        <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>"><?php echo htmlspecialchars($user['email']); ?></a>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <strong>Telefono:</strong><br>
                                                                        <?php echo htmlspecialchars($user['telefono'] ?: 'Non fornito'); ?>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <strong>Nome Associazione:</strong><br>
                                                                        <?php echo htmlspecialchars($user['nome_associazione'] ?? 'Non fornito'); ?>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <strong>Data Registrazione:</strong><br>
                                                                        <?php echo date('d/m/Y H:i:s', strtotime($user['created_at'])); ?>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <strong>IP Address:</strong><br>
                                                                        <code><?php echo htmlspecialchars($user['ip_address'] ?? 'N/A'); ?></code>
                                                                    </div>
                                                                    <?php if ($user['note_registrazione']): ?>
                                                                        <div class="col-12 mb-3">
                                                                            <strong>Note:</strong><br>
                                                                            <div class="alert alert-info">
                                                                                <?php echo nl2br(htmlspecialchars($user['note_registrazione'])); ?>
                                                                            </div>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recently Processed Users -->
    <?php if (!empty($recent_users)): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> Processati di Recente (ultimi 30 giorni)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Utente</th>
                                        <th>Email</th>
                                        <th>Stato</th>
                                        <th>Processato da</th>
                                        <th>Data</th>
                                        <th>Motivazione</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></td>
                                            <td><small><?php echo htmlspecialchars($user['email']); ?></small></td>
                                            <td>
                                                <?php if ($user['status'] === 'approved'): ?>
                                                    <span class="badge bg-success">Approvato</span>
                                                <?php elseif ($user['status'] === 'rejected'): ?>
                                                    <span class="badge bg-danger">Rifiutato</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($user['approved_by_name'] ?? 'Sistema'); ?></small></td>
                                            <td><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($user['approved_at'])); ?></small></td>
                                            <td>
                                                <?php if ($user['rejection_reason']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($user['rejection_reason']); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.avatar-circle {
    font-size: 0.875rem;
}
</style>
