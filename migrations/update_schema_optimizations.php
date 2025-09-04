<?php
/**
 * Migration: Add helpful indexes and normalize over-encoded text
 */

function indexExists(PDO $pdo, string $table, string $index): bool {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$index]);
        return (bool)$stmt->fetch();
    } catch (PDOException $e) { return false; }
}

function addIndexIfMissing(PDO $pdo, string $table, string $index, string $ddl) {
    if (!indexExists($pdo, $table, $index)) {
        $pdo->exec($ddl);
        return true;
    }
    return false;
}

function deepDecode(string $s): string {
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $s) break;
        $s = $decoded;
    }
    return $s;
}

function runSchemaOptimizations(PDO $pdo) {
    $changes = [];
    try {
        // Add indexes on soci
        if (addIndexIfMissing($pdo, 'soci', 'idx_soci_assoc', "ALTER TABLE soci ADD INDEX idx_soci_assoc (associazione_id)")) $changes[] = 'Added soci.idx_soci_assoc';
        if (addIndexIfMissing($pdo, 'soci', 'idx_soci_tipo', "ALTER TABLE soci ADD INDEX idx_soci_tipo (tipo_socio_id)")) $changes[] = 'Added soci.idx_soci_tipo';
        if (addIndexIfMissing($pdo, 'soci', 'idx_soci_cat', "ALTER TABLE soci ADD INDEX idx_soci_cat (categoria_socio_id)")) $changes[] = 'Added soci.idx_soci_cat';
        if (addIndexIfMissing($pdo, 'soci', 'idx_soci_sede', "ALTER TABLE soci ADD INDEX idx_soci_sede (sede_id)")) $changes[] = 'Added soci.idx_soci_sede';

        // Add indexes on tessere
        if (addIndexIfMissing($pdo, 'tessere', 'idx_tessere_assoc_anno', "ALTER TABLE tessere ADD INDEX idx_tessere_assoc_anno (associazione_id, anno_validita)")) $changes[] = 'Added tessere.idx_tessere_assoc_anno';
        if (addIndexIfMissing($pdo, 'tessere', 'idx_tessere_socio', "ALTER TABLE tessere ADD INDEX idx_tessere_socio (socio_id)")) $changes[] = 'Added tessere.idx_tessere_socio';
        if (addIndexIfMissing($pdo, 'tessere', 'idx_tessere_emissione', "ALTER TABLE tessere ADD INDEX idx_tessere_emissione (socio_id, associazione_id, data_emissione)")) $changes[] = 'Added tessere.idx_tessere_emissione';
        if (addIndexIfMissing($pdo, 'tessere', 'idx_tessere_template', "ALTER TABLE tessere ADD INDEX idx_tessere_template (template_tessera)")) $changes[] = 'Added tessere.idx_tessere_template';
        if (addIndexIfMissing($pdo, 'tessere', 'idx_tessere_scadenza', "ALTER TABLE tessere ADD INDEX idx_tessere_scadenza (data_scadenza)")) $changes[] = 'Added tessere.idx_tessere_scadenza';

        // Normalize over-encoded descriptions
        foreach ([
            ['table' => 'tipi_socio', 'col' => 'descrizione'],
            ['table' => 'categorie_socio', 'col' => 'descrizione'],
        ] as $t) {
            try {
                $table = $t['table']; $col = $t['col'];
                $rows = $pdo->query("SELECT id, `$col` FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                $upd = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE id = ?");
                $updated = 0;
                foreach ($rows as $r) {
                    $orig = (string)($r[$col] ?? '');
                    if ($orig === '') continue;
                    $dec = deepDecode($orig);
                    if ($dec !== $orig) { $upd->execute([$dec, $r['id']]); $updated++; }
                }
                if ($updated > 0) $changes[] = "Normalized $updated rows in $table.$col";
            } catch (PDOException $e) {}
        }

        return ['success' => true, 'message' => 'Schema optimizations executed.', 'changes' => $changes];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

