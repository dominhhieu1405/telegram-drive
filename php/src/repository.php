<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function verifyApiKey(?string $apiKey): ?array
{
    if (!$apiKey) {
        return null;
    }

    $stmt = db()->prepare('SELECT `key`, label, created_at, created_by, usage_count, last_used FROM api_keys WHERE `key` = ?');
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function listApiKeys(): array
{
    $stmt = db()->query('SELECT `key`, label, created_at AS createdAt, created_by AS createdBy, usage_count AS usageCount, last_used AS lastUsed FROM api_keys ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function createApiKey(string $label, string $createdBy = 'admin'): array
{
    $key = 'tap_' . bin2hex(random_bytes(24));
    $now = (int) round(microtime(true) * 1000);

    $stmt = db()->prepare('INSERT INTO api_keys(`key`, label, created_at, created_by, usage_count) VALUES(?, ?, ?, ?, 0)');
    $stmt->execute([$key, $label ?: 'Untitled Key', $now, $createdBy]);

    return [
        'key' => $key,
        'label' => $label ?: 'Untitled Key',
        'createdAt' => $now,
        'createdBy' => $createdBy,
        'usageCount' => 0,
    ];
}

function deleteApiKey(string $key): void
{
    $stmt = db()->prepare('DELETE FROM api_keys WHERE `key` = ?');
    $stmt->execute([$key]);
}

function touchApiKey(string $key): void
{
    $now = (int) round(microtime(true) * 1000);
    $stmt = db()->prepare('UPDATE api_keys SET usage_count = usage_count + 1, last_used = ? WHERE `key` = ?');
    $stmt->execute([$now, $key]);
}

function enforceRateLimit(string $identity, bool $viaApiKey, int $limitPublic, int $limitApi): array
{
    $windowMs = 60 * 60 * 1000;
    $now = (int) round(microtime(true) * 1000);
    $limit = $viaApiKey ? $limitApi : $limitPublic;

    $stmt = db()->prepare('SELECT window_start, window_count FROM usage_stats WHERE id = ?');
    $stmt->execute([$identity]);
    $row = $stmt->fetch();

    $windowStart = (int)($row['window_start'] ?? $now);
    $windowCount = (int)($row['window_count'] ?? 0);

    if (($now - $windowStart) >= $windowMs) {
        $windowStart = $now;
        $windowCount = 0;
    }

    if ($windowCount >= $limit) {
        return ['allowed' => false, 'retryAfter' => max(0, $windowMs - ($now - $windowStart)), 'windowStart' => $windowStart, 'windowCount' => $windowCount];
    }

    return ['allowed' => true, 'windowStart' => $windowStart, 'windowCount' => $windowCount];
}

function saveUsageStats(array $fingerprint, array $input, array $windowState): void
{
    $now = (int) round(microtime(true) * 1000);
    $identity = $fingerprint['identity'];

    $stmt = db()->prepare('SELECT * FROM usage_stats WHERE id = ?');
    $stmt->execute([$identity]);
    $prev = $stmt->fetch() ?: [];

    $uploads = ((int)($prev['uploads'] ?? 0)) + 1;
    $totalBytes = ((int)($prev['total_bytes'] ?? 0)) + (int)($input['bytes'] ?? 0);
    $apiUploads = ((int)($prev['api_uploads'] ?? 0)) + (!empty($input['viaApiKey']) ? 1 : 0);

    $upsert = db()->prepare(
        'INSERT INTO usage_stats(id, uploads, total_bytes, api_uploads, last_upload, last_file_name, last_file_type, user_agent, country, device, browser, ip_hash, window_start, window_count)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         uploads = VALUES(uploads), total_bytes = VALUES(total_bytes), api_uploads = VALUES(api_uploads), last_upload = VALUES(last_upload),
         last_file_name = VALUES(last_file_name), last_file_type = VALUES(last_file_type), user_agent = VALUES(user_agent), country = VALUES(country),
         device = VALUES(device), browser = VALUES(browser), ip_hash = VALUES(ip_hash), window_start = VALUES(window_start), window_count = VALUES(window_count)'
    );

    $upsert->execute([
        $identity,
        $uploads,
        $totalBytes,
        $apiUploads,
        $now,
        $input['fileName'] ?? null,
        $input['fileType'] ?? null,
        $fingerprint['userAgent'],
        $fingerprint['country'],
        $fingerprint['device'],
        $fingerprint['browser'],
        $fingerprint['ipHash'],
        (int)$windowState['windowStart'],
        ((int)$windowState['windowCount']) + 1,
    ]);
}

function listUsageStats(): array
{
    $items = db()->query('SELECT id, uploads, total_bytes AS totalBytes, api_uploads AS apiUploads, last_upload AS lastUpload, last_file_name AS lastFileName, last_file_type AS lastFileType, user_agent AS userAgent, country, device, browser, ip_hash AS ipHash FROM usage_stats ORDER BY last_upload DESC')->fetchAll();

    $summary = db()->query('SELECT COALESCE(SUM(uploads),0) AS uploads, COALESCE(SUM(total_bytes),0) AS bytes, COALESCE(SUM(api_uploads),0) AS apiUploads, MAX(last_upload) AS lastUpload FROM usage_stats')->fetch();

    return ['items' => $items, 'summary' => $summary];
}

function deleteStat(string $id): void
{
    $stmt = db()->prepare('DELETE FROM usage_stats WHERE id = ?');
    $stmt->execute([$id]);
}

function saveUploadMetadata(array $meta): void
{
    $stmt = db()->prepare('INSERT INTO uploads(encoded_id, file_id, original_name, file_type, file_size, uploaded_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE original_name=VALUES(original_name), file_type=VALUES(file_type), file_size=VALUES(file_size), uploaded_at=VALUES(uploaded_at)');
    $stmt->execute([$meta['encodedId'], $meta['fileId'], $meta['originalName'], $meta['fileType'], $meta['size'], $meta['uploadedAt']]);
}

function findUploadMetadata(string $encodedId): ?array
{
    $stmt = db()->prepare('SELECT encoded_id AS encodedFileId, file_id AS fileId, original_name AS originalName, file_type AS fileType, file_size AS size, uploaded_at AS uploadedAt FROM uploads WHERE encoded_id = ?');
    $stmt->execute([$encodedId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
