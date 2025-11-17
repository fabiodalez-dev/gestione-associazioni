<?php
/**
 * Plugin Name: Dashboard Analytics
 * Plugin URI: https://gestione-associazioni.local/plugins/dashboard-analytics
 * Description: Aggiunge widget analytics avanzati alla dashboard: Grafici crescita soci, Mappa geografica, Trend pagamenti, Analytics predittive
 * Version: 1.0.0
 * Author: Gestione Associazioni Team
 * Author URI: https://gestione-associazioni.local
 * License: GPL2
 * Requires PHP: 7.4
 */

class Plugin_dashboard_analytics {
    /**
     * Database PDO connection
     */
    private $pdo;

    /**
     * Inizializza il plugin
     */
    public function init() {
        global $pdo;
        $this->pdo = $pdo;

        $this->registerHooks();
    }

    /**
     * Registra hooks e filtri
     */
    private function registerHooks() {
        // Aggiungi widget dashboard
        HookManager::addAction('dashboard_widgets', [$this, 'renderGrowthChart'], 5);
        HookManager::addAction('dashboard_widgets', [$this, 'renderGeographicMap'], 5);
        HookManager::addAction('dashboard_widgets', [$this, 'renderPaymentTrends'], 5);
        HookManager::addAction('dashboard_widgets', [$this, 'renderPredictiveAnalytics'], 5);

        // Modifica dati grafici
        HookManager::addFilter('chart_data', [$this, 'enhanceChartData'], 10);

        // Aggiungi statistiche custom
        HookManager::addFilter('dashboard_stats', [$this, 'addCustomStats'], 10);

        // Aggiungi CSS/JS
        HookManager::addAction('admin_head', [$this, 'enqueueAssets']);
    }

    /**
     * Widget: Grafico crescita soci
     */
    public function renderGrowthChart() {
        try {
            // Ottieni dati ultimi 12 mesi
            $stmt = $this->pdo->query("SELECT
                DATE_FORMAT(data_iscrizione, '%Y-%m') as month,
                COUNT(*) as count
                FROM soci
                WHERE data_iscrizione >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(data_iscrizione, '%Y-%m')
                ORDER BY month ASC");

            $data = $stmt->fetchAll();

            $labels = array_column($data, 'month');
            $values = array_column($data, 'count');

            // Calcola trend
            $total_new = array_sum($values);
            $avg_monthly = $total_new > 0 ? round($total_new / count($values), 1) : 0;

            ?>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-graph-up text-success me-2"></i>
                            Crescita Soci (12 Mesi)
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="growthChart" height="100"></canvas>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="text-muted small">Nuovi Iscritti</div>
                                <div class="h4 mb-0 text-success"><?php echo $total_new; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Media Mensile</div>
                                <div class="h4 mb-0 text-primary"><?php echo $avg_monthly; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function() {
                const ctx = document.getElementById('growthChart');
                if (!ctx) return;

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($labels); ?>,
                        datasets: [{
                            label: 'Nuovi Soci',
                            data: <?php echo json_encode($values); ?>,
                            borderColor: 'rgb(75, 192, 192)',
                            backgroundColor: 'rgba(75, 192, 192, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            })();
            </script>
            <?php
        } catch (PDOException $e) {
            error_log("Dashboard Analytics: Errore growth chart: " . $e->getMessage());
        }
    }

    /**
     * Widget: Mappa geografica distribuzione soci
     */
    public function renderGeographicMap() {
        try {
            // Raggruppa per provincia
            $stmt = $this->pdo->query("SELECT
                provincia,
                COUNT(*) as count
                FROM soci
                WHERE provincia IS NOT NULL AND provincia != ''
                GROUP BY provincia
                ORDER BY count DESC
                LIMIT 10");

            $provinces = $stmt->fetchAll();

            ?>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-geo-alt text-danger me-2"></i>
                            Distribuzione Geografica
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <canvas id="geoChart" height="100"></canvas>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Provincia</th>
                                        <th class="text-end">Soci</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total = array_sum(array_column($provinces, 'count'));
                                    foreach ($provinces as $prov):
                                        $percentage = $total > 0 ? round(($prov['count'] / $total) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($prov['provincia']); ?></strong></td>
                                        <td class="text-end"><?php echo $prov['count']; ?></td>
                                        <td>
                                            <div class="progress" style="height: 5px; width: 60px;">
                                                <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function() {
                const ctx = document.getElementById('geoChart');
                if (!ctx) return;

                const data = <?php echo json_encode($provinces); ?>;
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: data.map(p => p.provincia),
                        datasets: [{
                            data: data.map(p => p.count),
                            backgroundColor: [
                                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                                '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
                                '#4BC0C0', '#FF6384'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false }
                        }
                    }
                });
            })();
            </script>
            <?php
        } catch (PDOException $e) {
            error_log("Dashboard Analytics: Errore geo map: " . $e->getMessage());
        }
    }

    /**
     * Widget: Trend pagamenti
     */
    public function renderPaymentTrends() {
        try {
            // Analizza trend pagamenti per stato
            $stmt = $this->pdo->query("SELECT
                stato,
                COUNT(*) as count,
                SUM(importo) as total
                FROM quote
                WHERE anno >= YEAR(NOW()) - 1
                GROUP BY stato");

            $payments = $stmt->fetchAll();

            // Calcola tassi
            $total_quote = array_sum(array_column($payments, 'count'));
            $paid = 0;
            $overdue = 0;

            foreach ($payments as $p) {
                if ($p['stato'] === 'Pagata') $paid += $p['count'];
                if ($p['stato'] === 'Scaduta') $overdue += $p['count'];
            }

            $payment_rate = $total_quote > 0 ? round(($paid / $total_quote) * 100, 1) : 0;
            $overdue_rate = $total_quote > 0 ? round(($overdue / $total_quote) * 100, 1) : 0;

            ?>
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-cash-coin text-warning me-2"></i>
                            Analisi Pagamenti
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Tasso Pagamento</span>
                                <strong class="text-success"><?php echo $payment_rate; ?>%</strong>
                            </div>
                            <div class="progress mb-3" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $payment_rate; ?>%"></div>
                            </div>

                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Tasso Morosità</span>
                                <strong class="text-danger"><?php echo $overdue_rate; ?>%</strong>
                            </div>
                            <div class="progress mb-3" style="height: 10px;">
                                <div class="progress-bar bg-danger" style="width: <?php echo $overdue_rate; ?>%"></div>
                            </div>
                        </div>

                        <hr>

                        <div class="list-group list-group-flush">
                            <?php foreach ($payments as $p): ?>
                            <div class="list-group-item d-flex justify-content-between px-0">
                                <span>
                                    <?php
                                    $badge_color = [
                                        'Pagata' => 'success',
                                        'Da Pagare' => 'warning',
                                        'Scaduta' => 'danger',
                                        'In Scadenza' => 'info'
                                    ];
                                    $color = $badge_color[$p['stato']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>"><?php echo $p['stato']; ?></span>
                                </span>
                                <strong><?php echo number_format($p['total'], 2, ',', '.'); ?> €</strong>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        } catch (PDOException $e) {
            error_log("Dashboard Analytics: Errore payment trends: " . $e->getMessage());
        }
    }

    /**
     * Widget: Analytics predittive
     */
    public function renderPredictiveAnalytics() {
        try {
            // Predizione nuovi soci prossimo mese (basata su media ultimi 3 mesi)
            $stmt = $this->pdo->query("SELECT
                COUNT(*) / 3 as avg_monthly
                FROM soci
                WHERE data_iscrizione >= DATE_SUB(NOW(), INTERVAL 3 MONTH)");

            $prediction = $stmt->fetch();
            $predicted_new = round($prediction['avg_monthly'] ?? 0);

            // Predizione rinnovi tessere in scadenza
            $stmt = $this->pdo->query("SELECT
                COUNT(*) as expiring
                FROM tessere
                WHERE data_scadenza BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
                AND stato = 'Attiva'");

            $expiring = $stmt->fetch();

            // Calcola revenue prevista
            $stmt = $this->pdo->query("SELECT AVG(importo) as avg_quota FROM quote WHERE stato = 'Pagata'");
            $avg_quota = $stmt->fetch()['avg_quota'] ?? 50;
            $predicted_revenue = ($predicted_new + $expiring['expiring']) * $avg_quota;

            ?>
            <div class="col-lg-4">
                <div class="card shadow-sm border-primary">
                    <div class="card-header bg-primary text-white">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-stars me-2"></i>
                            Analytics Predittive
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info border-0 mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <small>Previsioni basate su dati storici ultimi 3 mesi</small>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex align-items-center mb-2">
                                <div class="rounded-circle bg-success bg-opacity-10 p-2 me-3">
                                    <i class="bi bi-person-plus fs-5 text-success"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Nuovi Soci Previsti</div>
                                    <div class="h3 mb-0 text-success">~<?php echo $predicted_new; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex align-items-center mb-2">
                                <div class="rounded-circle bg-warning bg-opacity-10 p-2 me-3">
                                    <i class="bi bi-credit-card fs-5 text-warning"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Rinnovi in Scadenza</div>
                                    <div class="h3 mb-0 text-warning"><?php echo $expiring['expiring']; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="rounded-circle bg-primary bg-opacity-10 p-2 me-3">
                                    <i class="bi bi-cash-stack fs-5 text-primary"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Revenue Prevista</div>
                                    <div class="h3 mb-0 text-primary">€ <?php echo number_format($predicted_revenue, 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="text-center">
                            <small class="text-muted">
                                <i class="bi bi-clock-history me-1"></i>
                                Aggiornato: <?php echo date('d/m/Y H:i'); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        } catch (PDOException $e) {
            error_log("Dashboard Analytics: Errore predictive: " . $e->getMessage());
        }
    }

    /**
     * Migliora dati grafici esistenti
     *
     * @param array $data Dati grafici
     * @param string $type Tipo grafico
     * @return array Dati migliorati
     */
    public function enhanceChartData($data, $type) {
        // Aggiungi animazioni smooth
        if (!isset($data['options'])) {
            $data['options'] = [];
        }

        $data['options']['animation'] = [
            'duration' => 1000,
            'easing' => 'easeInOutQuart'
        ];

        return $data;
    }

    /**
     * Aggiungi statistiche custom
     *
     * @param array $stats Statistiche esistenti
     * @return array Statistiche con aggiunte
     */
    public function addCustomStats($stats) {
        try {
            // Aggiungi retention rate
            $stmt = $this->pdo->query("SELECT
                (SELECT COUNT(*) FROM soci WHERE data_iscrizione < DATE_SUB(NOW(), INTERVAL 1 YEAR)) as old_members,
                (SELECT COUNT(*) FROM soci WHERE stato = 'Attivo' AND data_iscrizione < DATE_SUB(NOW(), INTERVAL 1 YEAR)) as retained");

            $retention = $stmt->fetch();
            $retention_rate = $retention['old_members'] > 0 ?
                round(($retention['retained'] / $retention['old_members']) * 100, 1) : 0;

            $stats['retention_rate'] = $retention_rate;

            // Aggiungi lifetime value medio
            $stmt = $this->pdo->query("SELECT AVG(total) as ltv FROM (
                SELECT socio_id, SUM(importo) as total
                FROM quote WHERE stato = 'Pagata'
                GROUP BY socio_id
            ) as subquery");

            $ltv = $stmt->fetch();
            $stats['avg_lifetime_value'] = round($ltv['ltv'] ?? 0, 2);

        } catch (PDOException $e) {
            error_log("Dashboard Analytics: Errore custom stats: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Aggiungi assets (CSS/JS)
     */
    public function enqueueAssets() {
        ?>
        <!-- Chart.js per grafici analytics -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <style>
            .card.border-primary {
                border-width: 2px !important;
            }
        </style>
        <?php
    }

    /**
     * Attivazione plugin
     */
    public function activate() {
        error_log("Dashboard Analytics Plugin: Attivato - Widget analytics disponibili");
    }

    /**
     * Disattivazione plugin
     */
    public function deactivate() {
        error_log("Dashboard Analytics Plugin: Disattivato");
    }

    /**
     * Esporta dati analytics in JSON
     *
     * @return array Analytics data
     */
    public function exportAnalytics() {
        // Metodo per esportare tutti i dati analytics
        return [
            'generated_at' => date('c'),
            'plugin_version' => '1.0.0',
            'data' => [
                // Qui andrebbero tutti i dati analytics
            ]
        ];
    }
}
