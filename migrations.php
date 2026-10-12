<?php
declare(strict_types=1);

require_once __DIR__ . '/project_tree.php';

// One-time cleanup of duplicate CV projects. Runs once per database; the flag in profile records that it ran.
const DEDUPE_MIGRATION = 'migration_projects_dedupe_v1';
const DEDUPE_DROP_FOLDERS = ['weather_app_clean_architecture_with_bloc', 'classyar_app_7'];
const DEDUPE_MERGE_FROM = 'tobank_sdui_tobank_virtual_branch_of_gardeshgari_bank';
const DEDUPE_MERGE_INTO = 'tobank_sdui';

/** Folder name per project id, computed exactly as the IDE tree names them (duplicates get an id suffix). */
function folder_names(array $projects): array {
    $names = [];
    $used = [];
    foreach ($projects as $project) {
        $slug = project_slug($project);
        if (isset($used[$slug])) {
            $slug .= '_' . (int) $project['id'];
        }
        $used[$slug] = true;
        $names[(int) $project['id']] = $slug;
    }
    return $names;
}

/** Writes a line to data/migration.log. Logging must never block the migration. */
function migration_log(string $line): void {
    if (!is_dir(DATA_DIR)) {
        return;
    }
    @file_put_contents(DATA_DIR . '/migration.log', '[' . date('c') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Full backup of one project and its files and screenshots, as JSON, taken before anything is removed. */
function project_backup(PDO $pdo, int $id): string {
    $project = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $project->execute([$id]);
    $files = $pdo->prepare('SELECT * FROM project_files WHERE project_id = ?');
    $files->execute([$id]);
    $media = $pdo->prepare('SELECT * FROM project_media WHERE project_id = ?');
    $media->execute([$id]);
    return json_encode([
        'project' => $project->fetch(),
        'project_files' => $files->fetchAll(),
        'project_media' => $media->fetchAll(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function delete_project(PDO $pdo, int $id): void {
    $pdo->prepare('DELETE FROM project_files WHERE project_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM project_media WHERE project_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
}

/** Fills the target's empty fields from the duplicate, keeps the fuller text, moves its screenshots and files. */
function merge_project(PDO $pdo, int $fromId, int $intoId): void {
    $from = $pdo->query('SELECT * FROM projects WHERE id = ' . $fromId)->fetch();
    $into = $pdo->query('SELECT * FROM projects WHERE id = ' . $intoId)->fetch();

    $update = [];
    foreach (['summary', 'summary_fa'] as $field) {
        if (strlen((string) $from[$field]) > strlen((string) $into[$field])) {
            $update[$field] = $from[$field];
        }
    }
    foreach (['title_fa', 'url', 'github_url', 'appstore_url', 'playstore_url', 'bazaar_url'] as $field) {
        if (trim((string) $into[$field]) === '' && trim((string) $from[$field]) !== '') {
            $update[$field] = $from[$field];
        }
    }
    $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $into['tags'] . ',' . $from['tags'])))));
    if ($tags !== array_filter(array_map('trim', explode(',', (string) $into['tags'])))) {
        $update['tags'] = implode(', ', $tags);
    }
    if ($update) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($update)));
        $stmt = $pdo->prepare("UPDATE projects SET $set WHERE id = :id");
        $stmt->execute($update + ['id' => $intoId]);
    }

    $pdo->prepare('UPDATE project_media SET project_id = ? WHERE project_id = ?')->execute([$intoId, $fromId]);
    $pdo->prepare('UPDATE project_files SET project_id = ? WHERE project_id = ? AND path NOT IN (SELECT path FROM project_files WHERE project_id = ?)')
        ->execute([$intoId, $fromId, $intoId]);
    delete_project($pdo, $fromId);
}

function run_migrations(PDO $pdo): void {
    if ($pdo->query("SELECT 1 FROM profile WHERE k = '" . DEDUPE_MIGRATION . "'")->fetchColumn()) {
        return;
    }
    $projects = $pdo->query('SELECT * FROM projects ORDER BY sort, id DESC')->fetchAll();
    $byName = array_flip(folder_names($projects));
    $report = [];

    $pdo->beginTransaction();
    try {
        foreach (DEDUPE_DROP_FOLDERS as $folder) {
            if (!isset($byName[$folder])) {
                $report[] = "no match for folder $folder";
                continue;
            }
            $id = (int) $byName[$folder];
            $report[] = "deleted $folder (id $id): " . project_backup($pdo, $id);
            delete_project($pdo, $id);
        }
        if (isset($byName[DEDUPE_MERGE_FROM], $byName[DEDUPE_MERGE_INTO])) {
            $fromId = (int) $byName[DEDUPE_MERGE_FROM];
            $intoId = (int) $byName[DEDUPE_MERGE_INTO];
            $report[] = "merged " . DEDUPE_MERGE_FROM . " (id $fromId) into " . DEDUPE_MERGE_INTO . " (id $intoId); removed row: " . project_backup($pdo, $fromId);
            merge_project($pdo, $fromId, $intoId);
        } else {
            $report[] = 'merge skipped: ' . DEDUPE_MERGE_FROM . ' or ' . DEDUPE_MERGE_INTO . ' not found';
        }
        $pdo->exec("INSERT INTO profile (k, v) VALUES ('" . DEDUPE_MIGRATION . "', '1')");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        migration_log('FAILED: ' . $e->getMessage());
        throw $e;
    }
    foreach ($report as $line) {
        migration_log($line);
    }
}
