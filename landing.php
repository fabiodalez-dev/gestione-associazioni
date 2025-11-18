<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sistema avanzato di gestione associazioni - Gestisci soci, tessere, quote ed eventi in modo professionale">
    <meta name="keywords" content="gestione associazioni, gestione soci, membership management">
    <title>Gestione Associazioni - Sistema Professionale di Management</title>

    <!-- Local CSS -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/landing.css">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>👥</text></svg>">
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loader"></div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-landing fixed-top">
        <div class="container">
            <a class="navbar-brand" href="landing.php">
                <i class="bi bi-people-fill"></i>
                Gestione Associazioni
            </a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">Gestione Associazioni</h1>
                <h2 class="hero-subtitle">Sistema Professionale di Management</h2>
                <p class="hero-description">
                    Piattaforma completa per la gestione di soci, tessere, quote ed eventi.
                    Potente, sicura e facile da usare. Con sistema plugin estensibile per
                    personalizzare ogni aspetto della tua associazione.
                </p>

                <div class="btn-group-landing">
                    <a href="auth/register.php" class="btn-landing btn-primary-landing">
                        <i class="bi bi-person-plus-fill"></i>
                        <span>Registrati</span>
                    </a>
                    <a href="auth/login.php" class="btn-landing btn-secondary-landing">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Accedi</span>
                    </a>
                </div>

                <div class="mt-4">
                    <small style="color: rgba(192, 192, 192, 0.6);">
                        <i class="bi bi-info-circle"></i>
                        La registrazione richiede l'approvazione di un amministratore
                    </small>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <div class="row g-4">
                <!-- Feature 1 -->
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <h3 class="feature-title">Gestione Soci</h3>
                        <p class="feature-description">
                            Anagrafica completa con campi personalizzati, categorie,
                            gruppi dinamici e tag. Esporta dati in CSV, Excel o PDF.
                        </p>
                    </div>
                </div>

                <!-- Feature 2 -->
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-credit-card-2-front"></i>
                        </div>
                        <h3 class="feature-title">Tessere & Quote</h3>
                        <p class="feature-description">
                            Gestione tessere associative con QR code,
                            tracking pagamenti quote e scadenze automatiche.
                        </p>
                    </div>
                </div>

                <!-- Feature 3 -->
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h3 class="feature-title">Sicurezza OWASP</h3>
                        <p class="feature-description">
                            Protezione avanzata: CSRF tokens, rate limiting,
                            session management, security logging e HTTPS obbligatorio.
                        </p>
                    </div>
                </div>

                <!-- Feature 4 -->
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-puzzle"></i>
                        </div>
                        <h3 class="feature-title">Sistema Plugin</h3>
                        <p class="feature-description">
                            Estendi le funzionalità con plugin. 68+ hooks disponibili
                            per personalizzare ogni aspetto dell'applicazione.
                        </p>
                    </div>
                </div>

                <!-- Feature 5 -->
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-envelope"></i>
                        </div>
                        <h3 class="feature-title">Comunicazioni</h3>
                        <p class="feature-description">
                            Email massive con template personalizzabili,
                            tracking aperture e gestione eventi con RSVP.
                        </p>
                    </div>
                </div>

                <!-- Feature 6 -->
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <h3 class="feature-title">Analytics</h3>
                        <p class="feature-description">
                            Dashboard con statistiche in tempo reale,
                            grafici interattivi e report personalizzabili.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-landing">
        <div class="container">
            <p>
                <i class="bi bi-code-slash"></i>
                &copy; <?php echo date('Y'); ?> Gestione Associazioni -
                Powered by PHP 7.4+ & MySQL -
                <a href="https://github.com/your-repo" target="_blank" style="color: var(--color-silver); text-decoration: none;">
                    <i class="bi bi-github"></i> GitHub
                </a>
            </p>
        </div>
    </footer>

    <!-- Local JavaScript -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Hide loading overlay when page is loaded
        window.addEventListener('load', function() {
            const overlay = document.getElementById('loadingOverlay');
            setTimeout(() => {
                overlay.classList.add('hidden');
            }, 500);
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>
