<?php
/**
 * User Registration Page - With Admin Approval
 */

require_once __DIR__ . '/../config.php';

// Redirect if already logged in
if (isUserLoggedIn()) {
    redirect('index.php?page=dashboard');
}

$message = '';
$message_type = 'danger';
$show_form = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza. Riprova.';
    } else {
        // Validate input
        $nome = trim($_POST['nome'] ?? '');
        $cognome = trim($_POST['cognome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $nome_associazione = trim($_POST['nome_associazione'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $note = trim($_POST['note'] ?? '');

        // Validation
        $errors = [];

        if (empty($nome)) $errors[] = 'Il nome è obbligatorio';
        if (empty($cognome)) $errors[] = 'Il cognome è obbligatorio';
        if (empty($email)) $errors[] = 'L\'email è obbligatoria';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida';
        if (empty($nome_associazione)) $errors[] = 'Il nome dell\'associazione è obbligatorio';

        // Password validation
        if (empty($password)) {
            $errors[] = 'La password è obbligatoria';
        } elseif (strlen($password) < 12) {
            $errors[] = 'La password deve essere di almeno 12 caratteri';
        } elseif ($password !== $password_confirm) {
            $errors[] = 'Le password non coincidono';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'La password deve contenere almeno una lettera maiuscola';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'La password deve contenere almeno una lettera minuscola';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'La password deve contenere almeno un numero';
        }

        // Check if email already exists
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Email già registrata';
            }
        }

        if (!empty($errors)) {
            $message = implode('<br>', $errors);
        } else {
            try {
                // Create username from email
                $username = explode('@', $email)[0] . '_' . substr(md5($email), 0, 6);

                // Hash password
                $password_hash = password_hash($password, PASSWORD_BCRYPT);

                // Insert user with 'pending' status
                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (username, email, nome, cognome, telefono, nome_associazione, note_registrazione,
                     password_hash, role, status, attivo, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin_associazione', 'pending', FALSE, NOW())
                ");

                $stmt->execute([
                    $username,
                    $email,
                    $nome,
                    $cognome,
                    $telefono,
                    $nome_associazione,
                    $note,
                    $password_hash
                ]);

                $user_id = $pdo->lastInsertId();

                // Log registration
                logSecurityEvent($pdo, 'user_registered', "New user registration: $email", [
                    'user_id' => $user_id,
                    'email' => $email,
                    'nome' => $nome,
                    'cognome' => $cognome,
                    'nome_associazione' => $nome_associazione
                ], 'info');

                // Insert into user_approval_log
                $stmt = $pdo->prepare("
                    INSERT INTO user_approval_log
                    (user_id, action, ip_address, user_agent)
                    VALUES (?, 'registered', ?, ?)
                ");
                $stmt->execute([
                    $user_id,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                // Generate verification token
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("
                    INSERT INTO pending_registrations
                    (user_id, verification_token, ip_address, user_agent, expires_at)
                    VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ");
                $stmt->execute([
                    $user_id,
                    $token,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                $message = 'Registrazione completata con successo! Il tuo account è in attesa di approvazione da parte di un amministratore. Riceverai una email quando il tuo account verrà attivato.';
                $message_type = 'success';
                $show_form = false;

                // TODO: Send email to admins about new registration
                // TODO: Send confirmation email to user

            } catch (PDOException $e) {
                error_log("Registration error: " . $e->getMessage());
                $message = 'Errore durante la registrazione. Riprova più tardi.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione - Gestione Associazioni</title>

    <!-- Local CSS -->
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/landing.css">

    <style>
        .register-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }

        .register-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(192, 192, 192, 0.2);
            border-radius: 20px;
            padding: 3rem;
            max-width: 600px;
            width: 100%;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 50px rgba(0, 0, 0, 0.5);
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .register-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-white);
            margin-bottom: 0.5rem;
        }

        .register-subtitle {
            color: var(--color-silver);
            font-size: 1rem;
        }

        .form-label {
            color: var(--color-white);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-control,
        .form-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(192, 192, 192, 0.2);
            color: var(--color-white);
            padding: 0.75rem 1rem;
            border-radius: 10px;
            transition: var(--transition);
        }

        .form-control:focus,
        .form-select:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--color-silver);
            color: var(--color-white);
            box-shadow: 0 0 0 0.25rem rgba(192, 192, 192, 0.1);
        }

        .form-control::placeholder {
            color: rgba(192, 192, 192, 0.5);
        }

        .btn-register {
            background: var(--color-white);
            color: var(--color-black);
            border: none;
            padding: 0.875rem 2rem;
            font-weight: 600;
            border-radius: 50px;
            width: 100%;
            transition: var(--transition);
        }

        .btn-register:hover {
            background: var(--color-silver);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 255, 255, 0.2);
        }

        .alert-custom {
            background: rgba(192, 192, 192, 0.1);
            border: 1px solid rgba(192, 192, 192, 0.2);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .alert-custom.alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
        }

        .alert-custom.alert-success {
            background: rgba(25, 135, 84, 0.1);
            border-color: rgba(25, 135, 84, 0.3);
            color: #51cf66;
        }

        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-link a {
            color: var(--color-silver);
            text-decoration: none;
            transition: var(--transition);
        }

        .back-link a:hover {
            color: var(--color-white);
        }

        .password-requirements {
            font-size: 0.875rem;
            color: rgba(192, 192, 192, 0.7);
            margin-top: 0.5rem;
        }

        .password-requirements ul {
            list-style: none;
            padding-left: 0;
        }

        .password-requirements li {
            padding-left: 1.5rem;
            position: relative;
        }

        .password-requirements li::before {
            content: '•';
            position: absolute;
            left: 0.5rem;
            color: var(--color-silver);
        }

        .required-field::after {
            content: ' *';
            color: var(--color-silver);
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="register-card">
                        <div class="register-header">
                            <a href="../landing.php" style="color: var(--color-silver); text-decoration: none; font-size: 2rem;">
                                <i class="bi bi-people-fill"></i>
                            </a>
                            <h1 class="register-title">Crea Account</h1>
                            <p class="register-subtitle">Richiedi l'accesso alla piattaforma</p>
                        </div>

                        <?php if ($message): ?>
                            <div class="alert-custom alert-<?php echo $message_type; ?>">
                                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                                <?php echo $message; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($show_form): ?>
                            <form method="POST" action="register.php">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="nome" class="form-label required-field">Nome</label>
                                        <input type="text" class="form-control" id="nome" name="nome"
                                               value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>"
                                               placeholder="Mario" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="cognome" class="form-label required-field">Cognome</label>
                                        <input type="text" class="form-control" id="cognome" name="cognome"
                                               value="<?php echo htmlspecialchars($_POST['cognome'] ?? ''); ?>"
                                               placeholder="Rossi" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="form-label required-field">Email</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                           placeholder="mario.rossi@example.com" required>
                                </div>

                                <div class="mb-3">
                                    <label for="telefono" class="form-label">Telefono</label>
                                    <input type="tel" class="form-control" id="telefono" name="telefono"
                                           value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>"
                                           placeholder="+39 123 456 7890">
                                </div>

                                <div class="mb-3">
                                    <label for="nome_associazione" class="form-label required-field">Nome Associazione</label>
                                    <input type="text" class="form-control" id="nome_associazione" name="nome_associazione"
                                           value="<?php echo htmlspecialchars($_POST['nome_associazione'] ?? ''); ?>"
                                           placeholder="Associazione Sportiva XYZ" required>
                                </div>

                                <div class="mb-3">
                                    <label for="password" class="form-label required-field">Password</label>
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="••••••••••••" required>
                                    <div class="password-requirements">
                                        <strong>Requisiti password:</strong>
                                        <ul>
                                            <li>Minimo 12 caratteri</li>
                                            <li>Almeno una lettera maiuscola</li>
                                            <li>Almeno una lettera minuscola</li>
                                            <li>Almeno un numero</li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="password_confirm" class="form-label required-field">Conferma Password</label>
                                    <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                           placeholder="••••••••••••" required>
                                </div>

                                <div class="mb-4">
                                    <label for="note" class="form-label">Note (opzionale)</label>
                                    <textarea class="form-control" id="note" name="note" rows="3"
                                              placeholder="Informazioni aggiuntive sulla tua associazione..."><?php echo htmlspecialchars($_POST['note'] ?? ''); ?></textarea>
                                </div>

                                <div class="alert-custom" style="background: rgba(192, 192, 192, 0.05); border-color: rgba(192, 192, 192, 0.15);">
                                    <i class="bi bi-info-circle"></i>
                                    <small style="color: rgba(192, 192, 192, 0.8);">
                                        La tua registrazione verrà esaminata da un amministratore.
                                        Riceverai una email di conferma quando il tuo account verrà approvato.
                                    </small>
                                </div>

                                <button type="submit" class="btn-register">
                                    <i class="bi bi-person-plus-fill"></i> Registrati
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="text-center mt-4">
                                <a href="login.php" class="btn-landing btn-primary-landing">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    <span>Vai al Login</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="back-link">
                            <a href="../landing.php">
                                <i class="bi bi-arrow-left"></i> Torna alla Home
                            </a>
                            <?php if ($show_form): ?>
                                | <a href="login.php">Hai già un account? Accedi</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Local JavaScript -->
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password strength indicator
        const password = document.getElementById('password');
        const requirements = {
            length: false,
            uppercase: false,
            lowercase: false,
            number: false
        };

        password?.addEventListener('input', function() {
            const value = this.value;

            // Check requirements
            requirements.length = value.length >= 12;
            requirements.uppercase = /[A-Z]/.test(value);
            requirements.lowercase = /[a-z]/.test(value);
            requirements.number = /[0-9]/.test(value);

            // Update UI (optional: add visual feedback)
        });

        // Confirm password match
        const passwordConfirm = document.getElementById('password_confirm');
        passwordConfirm?.addEventListener('input', function() {
            if (this.value && this.value !== password.value) {
                this.setCustomValidity('Le password non coincidono');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
