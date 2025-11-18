<?php
/**
 * Analytics Manager
 * Comprehensive analytics and reporting system
 */

class AnalyticsManager {
    private $pdo;
    private $associazione_id;
    private $cache_enabled = true;
    private $cache_ttl = 3600; // 1 hour

    public function __construct($pdo, $associazione_id = null) {
        $this->pdo = $pdo;
        $this->associazione_id = $associazione_id;
    }

    /**
     * Get cached data or execute query
     */
    private function getCached($key, $callback, $ttl = null) {
        if (!$this->cache_enabled) {
            return $callback();
        }

        $ttl = $ttl ?? $this->cache_ttl;
        $cache_key = $this->associazione_id . '_' . $key;

        // Check cache
        $stmt = $this->pdo->prepare("
            SELECT data FROM analytics_cache
            WHERE cache_key = ? AND expires_at > NOW()
        ");
        $stmt->execute([$cache_key]);
        $cached = $stmt->fetch();

        if ($cached) {
            return json_decode($cached['data'], true);
        }

        // Execute callback and cache result
        $data = $callback();

        $stmt = $this->pdo->prepare("
            INSERT INTO analytics_cache (cache_key, associazione_id, data, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at), updated_at = NOW()
        ");
        $stmt->execute([$cache_key, $this->associazione_id, json_encode($data), $ttl]);

        return $data;
    }

    /**
     * Clear cache for specific key or all
     */
    public function clearCache($key = null) {
        if ($key) {
            $cache_key = $this->associazione_id . '_' . $key;
            $stmt = $this->pdo->prepare("DELETE FROM analytics_cache WHERE cache_key = ?");
            $stmt->execute([$cache_key]);
        } else {
            $stmt = $this->pdo->prepare("DELETE FROM analytics_cache WHERE associazione_id = ?");
            $stmt->execute([$this->associazione_id]);
        }
    }

    /**
     * Get KPI Dashboard Summary
     */
    public function getKPIDashboard($date_from = null, $date_to = null) {
        $date_from = $date_from ?? date('Y-m-01'); // First day of current month
        $date_to = $date_to ?? date('Y-m-d');

        return $this->getCached("kpi_dashboard_{$date_from}_{$date_to}", function() use ($date_from, $date_to) {
            $where = $this->associazione_id ? "WHERE s.associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    -- Members KPIs
                    COUNT(DISTINCT s.id) as total_members,
                    SUM(CASE WHEN s.stato = 'attivo' THEN 1 ELSE 0 END) as active_members,
                    SUM(CASE WHEN s.data_iscrizione >= '$date_from' AND s.data_iscrizione <= '$date_to' THEN 1 ELSE 0 END) as new_members,
                    SUM(CASE WHEN s.stato = 'sospeso' THEN 1 ELSE 0 END) as suspended_members,

                    -- Cards KPIs
                    COUNT(DISTINCT t.id) as total_cards,
                    SUM(CASE WHEN t.stato = 'attiva' AND t.data_scadenza >= CURDATE() THEN 1 ELSE 0 END) as active_cards,
                    SUM(CASE WHEN t.data_scadenza < CURDATE() THEN 1 ELSE 0 END) as expired_cards,
                    SUM(CASE WHEN t.data_emissione >= '$date_from' AND t.data_emissione <= '$date_to' THEN 1 ELSE 0 END) as new_cards,

                    -- Financial KPIs
                    COALESCE(SUM(CASE WHEN q.stato = 'pagata' THEN q.importo ELSE 0 END), 0) as total_revenue,
                    COALESCE(SUM(CASE WHEN q.stato = 'pagata' AND q.data_pagamento >= '$date_from' AND q.data_pagamento <= '$date_to' THEN q.importo ELSE 0 END), 0) as period_revenue,
                    COALESCE(SUM(CASE WHEN q.stato = 'in_attesa' THEN q.importo ELSE 0 END), 0) as pending_revenue,
                    COUNT(DISTINCT CASE WHEN q.stato = 'pagata' AND q.data_pagamento >= '$date_from' AND q.data_pagamento <= '$date_to' THEN q.id END) as period_payments

                FROM soci s
                LEFT JOIN tessere t ON s.id = t.socio_id
                LEFT JOIN quote q ON s.id = q.socio_id
                $where
            ")->fetch();

            // Calculate trends (compare with previous period)
            $days = (strtotime($date_to) - strtotime($date_from)) / 86400;
            $prev_from = date('Y-m-d', strtotime($date_from . " -$days days"));
            $prev_to = date('Y-m-d', strtotime($date_to . " -$days days"));

            $prev_data = $this->pdo->query("
                SELECT
                    SUM(CASE WHEN s.data_iscrizione >= '$prev_from' AND s.data_iscrizione <= '$prev_to' THEN 1 ELSE 0 END) as prev_new_members,
                    COALESCE(SUM(CASE WHEN q.stato = 'pagata' AND q.data_pagamento >= '$prev_from' AND q.data_pagamento <= '$prev_to' THEN q.importo ELSE 0 END), 0) as prev_revenue,
                    SUM(CASE WHEN t.data_emissione >= '$prev_from' AND t.data_emissione <= '$prev_to' THEN 1 ELSE 0 END) as prev_new_cards
                FROM soci s
                LEFT JOIN tessere t ON s.id = t.socio_id
                LEFT JOIN quote q ON s.id = q.socio_id
                $where
            ")->fetch();

            // Calculate percentage changes
            $data['new_members_trend'] = $this->calculateTrend($data['new_members'], $prev_data['prev_new_members']);
            $data['revenue_trend'] = $this->calculateTrend($data['period_revenue'], $prev_data['prev_revenue']);
            $data['cards_trend'] = $this->calculateTrend($data['new_cards'], $prev_data['prev_new_cards']);

            // Add computed metrics
            $data['retention_rate'] = $data['total_members'] > 0 ? round(($data['active_members'] / $data['total_members']) * 100, 2) : 0;
            $data['card_coverage'] = $data['active_members'] > 0 ? round(($data['active_cards'] / $data['active_members']) * 100, 2) : 0;
            $data['avg_revenue_per_member'] = $data['active_members'] > 0 ? round($data['total_revenue'] / $data['active_members'], 2) : 0;

            return $data;
        }, 1800); // Cache for 30 minutes
    }

    /**
     * Get Members Growth Chart Data
     */
    public function getMembersGrowthChart($months = 12) {
        return $this->getCached("members_growth_$months", function() use ($months) {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    DATE_FORMAT(data_iscrizione, '%Y-%m') as month,
                    COUNT(*) as new_members,
                    SUM(COUNT(*)) OVER (ORDER BY DATE_FORMAT(data_iscrizione, '%Y-%m')) as cumulative
                FROM soci
                $where
                AND data_iscrizione >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
                GROUP BY DATE_FORMAT(data_iscrizione, '%Y-%m')
                ORDER BY month
            ")->fetchAll();

            $labels = [];
            $new_members = [];
            $cumulative = [];

            foreach ($data as $row) {
                $labels[] = date('M Y', strtotime($row['month'] . '-01'));
                $new_members[] = (int)$row['new_members'];
                $cumulative[] = (int)$row['cumulative'];
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Nuovi Soci',
                        'data' => $new_members,
                        'type' => 'bar'
                    ],
                    [
                        'label' => 'Totale Cumulativo',
                        'data' => $cumulative,
                        'type' => 'line'
                    ]
                ]
            ];
        });
    }

    /**
     * Get Members by Category
     */
    public function getMembersByCategory() {
        return $this->getCached("members_by_category", function() {
            $where = $this->associazione_id ? "WHERE s.associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    COALESCE(cs.nome, 'Nessuna Categoria') as category,
                    COUNT(s.id) as count,
                    SUM(CASE WHEN s.stato = 'attivo' THEN 1 ELSE 0 END) as active_count
                FROM soci s
                LEFT JOIN categorie_socio cs ON s.categoria_socio_id = cs.id
                $where
                GROUP BY cs.nome
                ORDER BY count DESC
            ")->fetchAll();

            $labels = [];
            $values = [];
            $active = [];

            foreach ($data as $row) {
                $labels[] = $row['category'];
                $values[] = (int)$row['count'];
                $active[] = (int)$row['active_count'];
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Totale',
                        'data' => $values
                    ],
                    [
                        'label' => 'Attivi',
                        'data' => $active
                    ]
                ]
            ];
        });
    }

    /**
     * Get Members by Status
     */
    public function getMembersByStatus() {
        return $this->getCached("members_by_status", function() {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT stato, COUNT(*) as count
                FROM soci
                $where
                GROUP BY stato
            ")->fetchAll();

            $labels = [];
            $values = [];
            $colors = [
                'attivo' => '#28a745',
                'sospeso' => '#ffc107',
                'radiato' => '#dc3545',
                'deceduto' => '#6c757d'
            ];
            $bg_colors = [];

            foreach ($data as $row) {
                $labels[] = ucfirst($row['stato']);
                $values[] = (int)$row['count'];
                $bg_colors[] = $colors[$row['stato']] ?? '#6c757d';
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'data' => $values,
                        'backgroundColor' => $bg_colors
                    ]
                ]
            ];
        });
    }

    /**
     * Get Geographic Distribution
     */
    public function getGeographicDistribution($group_by = 'provincia') {
        return $this->getCached("geographic_$group_by", function() use ($group_by) {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    COALESCE($group_by, 'Non specificato') as location,
                    COUNT(*) as count,
                    SUM(CASE WHEN stato = 'attivo' THEN 1 ELSE 0 END) as active_count
                FROM soci
                $where
                AND $group_by IS NOT NULL AND $group_by != ''
                GROUP BY $group_by
                ORDER BY count DESC
                LIMIT 20
            ")->fetchAll();

            return $data;
        });
    }

    /**
     * Get Cards Emissions Over Time
     */
    public function getCardsEmissionsChart($months = 12) {
        return $this->getCached("cards_emissions_$months", function() use ($months) {
            $where = $this->associazione_id ? "WHERE t.associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    DATE_FORMAT(data_emissione, '%Y-%m') as month,
                    COUNT(*) as emissions,
                    COALESCE(AVG(importo), 0) as avg_amount
                FROM tessere t
                $where
                AND data_emissione >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
                GROUP BY DATE_FORMAT(data_emissione, '%Y-%m')
                ORDER BY month
            ")->fetchAll();

            $labels = [];
            $emissions = [];
            $avg_amounts = [];

            foreach ($data as $row) {
                $labels[] = date('M Y', strtotime($row['month'] . '-01'));
                $emissions[] = (int)$row['emissions'];
                $avg_amounts[] = round((float)$row['avg_amount'], 2);
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Emissioni',
                        'data' => $emissions,
                        'yAxisID' => 'y'
                    ],
                    [
                        'label' => 'Importo Medio (€)',
                        'data' => $avg_amounts,
                        'yAxisID' => 'y1'
                    ]
                ]
            ];
        });
    }

    /**
     * Get Expiring Cards
     */
    public function getExpiringCards($days = 30) {
        $where = $this->associazione_id ? "WHERE t.associazione_id = '{$this->associazione_id}'" : "";

        $data = $this->pdo->query("
            SELECT
                t.id,
                t.numero_tessera,
                t.data_scadenza,
                DATEDIFF(t.data_scadenza, CURDATE()) as days_to_expire,
                CONCAT(s.nome, ' ', s.cognome) as socio_nome,
                s.email,
                s.telefono
            FROM tessere t
            JOIN soci s ON t.socio_id = s.id
            $where
            AND t.stato = 'attiva'
            AND t.data_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)
            ORDER BY t.data_scadenza ASC
        ")->fetchAll();

        return $data;
    }

    /**
     * Get Revenue Chart
     */
    public function getRevenueChart($months = 12) {
        return $this->getCached("revenue_chart_$months", function() use ($months) {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    DATE_FORMAT(data_pagamento, '%Y-%m') as month,
                    SUM(CASE WHEN stato = 'pagata' THEN importo ELSE 0 END) as revenue,
                    COUNT(CASE WHEN stato = 'pagata' THEN 1 END) as payments,
                    SUM(CASE WHEN stato = 'in_attesa' THEN importo ELSE 0 END) as pending
                FROM quote
                $where
                AND data_pagamento >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
                GROUP BY DATE_FORMAT(data_pagamento, '%Y-%m')
                ORDER BY month
            ")->fetchAll();

            $labels = [];
            $revenue = [];
            $payments = [];
            $pending = [];

            foreach ($data as $row) {
                $labels[] = date('M Y', strtotime($row['month'] . '-01'));
                $revenue[] = round((float)$row['revenue'], 2);
                $payments[] = (int)$row['payments'];
                $pending[] = round((float)$row['pending'], 2);
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Incassi (€)',
                        'data' => $revenue,
                        'type' => 'bar'
                    ],
                    [
                        'label' => 'In Attesa (€)',
                        'data' => $pending,
                        'type' => 'line'
                    ]
                ]
            ];
        });
    }

    /**
     * Get Revenue by Payment Method
     */
    public function getRevenueByPaymentMethod() {
        return $this->getCached("revenue_by_payment", function() {
            $where = $this->associazione_id ? "WHERE q.associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    COALESCE(t.metodo_pagamento, 'Non specificato') as method,
                    COUNT(DISTINCT q.id) as transaction_count,
                    SUM(q.importo) as total_amount
                FROM quote q
                LEFT JOIN tessere t ON q.socio_id = t.socio_id AND YEAR(q.data_scadenza) = YEAR(t.data_scadenza)
                $where
                AND q.stato = 'pagata'
                GROUP BY t.metodo_pagamento
                ORDER BY total_amount DESC
            ")->fetchAll();

            $labels = [];
            $values = [];

            foreach ($data as $row) {
                $labels[] = ucfirst($row['method']);
                $values[] = round((float)$row['total_amount'], 2);
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'data' => $values
                    ]
                ]
            ];
        });
    }

    /**
     * Get Events Analytics
     */
    public function getEventsAnalytics() {
        return $this->getCached("events_analytics", function() {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    COUNT(*) as total_events,
                    SUM(CASE WHEN data_inizio >= CURDATE() THEN 1 ELSE 0 END) as upcoming_events,
                    SUM(CASE WHEN data_fine < CURDATE() THEN 1 ELSE 0 END) as past_events,
                    AVG(max_partecipanti) as avg_capacity
                FROM eventi
                $where
            ")->fetch();

            return $data;
        });
    }

    /**
     * Get Top Performing Events
     */
    public function getTopEvents($limit = 10) {
        $where = $this->associazione_id ? "WHERE e.associazione_id = '{$this->associazione_id}'" : "";

        $data = $this->pdo->query("
            SELECT
                e.id,
                e.titolo,
                e.data_inizio,
                e.max_partecipanti,
                COUNT(ep.id) as registrations,
                ROUND((COUNT(ep.id) / e.max_partecipanti) * 100, 2) as fill_rate
            FROM eventi e
            LEFT JOIN eventi_partecipanti ep ON e.id = ep.evento_id
            $where
            GROUP BY e.id
            ORDER BY fill_rate DESC, registrations DESC
            LIMIT $limit
        ")->fetchAll();

        return $data;
    }

    /**
     * Calculate trend percentage
     */
    private function calculateTrend($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * Get Age Distribution
     */
    public function getAgeDistribution() {
        return $this->getCached("age_distribution", function() {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    CASE
                        WHEN TIMESTAMPDIFF(YEAR, data_nascita, CURDATE()) < 18 THEN '< 18'
                        WHEN TIMESTAMPDIFF(YEAR, data_nascita, CURDATE()) BETWEEN 18 AND 25 THEN '18-25'
                        WHEN TIMESTAMPDIFF(YEAR, data_nascita, CURDATE()) BETWEEN 26 AND 35 THEN '26-35'
                        WHEN TIMESTAMPDIFF(YEAR, data_nascita, CURDATE()) BETWEEN 36 AND 50 THEN '36-50'
                        WHEN TIMESTAMPDIFF(YEAR, data_nascita, CURDATE()) BETWEEN 51 AND 65 THEN '51-65'
                        ELSE '> 65'
                    END as age_group,
                    COUNT(*) as count
                FROM soci
                $where
                AND data_nascita IS NOT NULL
                GROUP BY age_group
                ORDER BY age_group
            ")->fetchAll();

            $labels = [];
            $values = [];

            foreach ($data as $row) {
                $labels[] = $row['age_group'];
                $values[] = (int)$row['count'];
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Soci per Età',
                        'data' => $values
                    ]
                ]
            ];
        });
    }

    /**
     * Get Engagement Score Distribution
     */
    public function getEngagementDistribution() {
        return $this->getCached("engagement_distribution", function() {
            $where = $this->associazione_id ? "WHERE associazione_id = '{$this->associazione_id}'" : "";

            $data = $this->pdo->query("
                SELECT
                    CASE
                        WHEN punteggio_engagement = 0 THEN 'Inattivo'
                        WHEN punteggio_engagement BETWEEN 1 AND 10 THEN 'Basso'
                        WHEN punteggio_engagement BETWEEN 11 AND 30 THEN 'Medio'
                        WHEN punteggio_engagement BETWEEN 31 AND 50 THEN 'Alto'
                        ELSE 'Molto Alto'
                    END as engagement_level,
                    COUNT(*) as count
                FROM soci
                $where
                GROUP BY engagement_level
                ORDER BY FIELD(engagement_level, 'Inattivo', 'Basso', 'Medio', 'Alto', 'Molto Alto')
            ")->fetchAll();

            $labels = [];
            $values = [];

            foreach ($data as $row) {
                $labels[] = $row['engagement_level'];
                $values[] = (int)$row['count'];
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'data' => $values
                    ]
                ]
            ];
        });
    }

    /**
     * Export data to CSV
     */
    public function exportToCSV($data, $filename) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

        if (!empty($data)) {
            // Headers
            fputcsv($output, array_keys($data[0]));

            // Data
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }

        fclose($output);
        exit;
    }
}
