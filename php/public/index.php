<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/repository.php';

$config = require __DIR__ . '/../src/config.php';
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($method === 'OPTIONS') {
    corsHeaders();
    http_response_code(200);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm', 'video/quicktime'];

if ($uri === '/api/auth' && $method === 'POST') {
    $body = getJsonBody();
    $ok = ($body['username'] ?? '') === $config['admin']['username'] && ($body['password'] ?? '') === $config['admin']['password'];
    if (!$ok) {
        jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
    }

    $token = 'Basic ' . base64_encode($config['admin']['username'] . ':' . $config['admin']['password']);
    jsonResponse(['success' => true, 'token' => $token, 'user' => ['username' => $config['admin']['username']]]);
}

if ($uri === '/api/api-keys') {
    requireAuth($config['admin']);
    if ($method === 'GET') {
        jsonResponse(['success' => true, 'keys' => listApiKeys()]);
    }
    if ($method === 'POST') {
        $body = getJsonBody();
        $key = createApiKey((string)($body['label'] ?? 'Untitled Key'));
        jsonResponse(['success' => true, 'key' => $key], 201);
    }
    if ($method === 'DELETE') {
        $body = getJsonBody();
        if (empty($body['key'])) {
            jsonResponse(['success' => false, 'error' => 'Missing API key'], 400);
        }
        deleteApiKey((string)$body['key']);
        jsonResponse(['success' => true]);
    }
}

if ($uri === '/api/stats') {
    requireAuth($config['admin']);
    if ($method === 'GET') {
        jsonResponse(['success' => true, ...listUsageStats()]);
    }
    if ($method === 'DELETE') {
        $body = getJsonBody();
        if (empty($body['id'])) {
            jsonResponse(['success' => false, 'error' => 'Missing stats id'], 400);
        }
        deleteStat((string)$body['id']);
        jsonResponse(['success' => true]);
    }
}

if ($uri === '/api/upload' && $method === 'POST') {
    if (empty($_FILES['file'])) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded'], 400);
    }

    $file = $_FILES['file'];
    if (($file['size'] ?? 0) > $config['limits']['max_upload_bytes']) {
        jsonResponse(['success' => false, 'error' => 'File size exceeds 5MB limit'], 413);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes, true)) {
        jsonResponse(['success' => false, 'error' => 'File type not supported'], 400);
    }

    $apiKeyHeader = $_SERVER['HTTP_X_API_KEY'] ?? null;
    $apiKeyRecord = verifyApiKey($apiKeyHeader);

    $fingerprint = fingerprintIdentity();
    $rate = enforceRateLimit($fingerprint['identity'], (bool)$apiKeyRecord, $config['limits']['public_hourly'], $config['limits']['api_hourly']);
    if (!$rate['allowed']) {
        jsonResponse(['success' => false, 'error' => 'Upload rate limit reached. Retry after ' . ceil($rate['retryAfter'] / 1000) . 's'], 429);
    }

    if (!$config['telegram']['bot_token'] || !$config['telegram']['chat_id']) {
        jsonResponse(['success' => false, 'error' => 'Telegram not configured'], 500);
    }

    $endpoint = str_starts_with($mime, 'image/') ? 'sendPhoto' : 'sendVideo';
    $field = str_starts_with($mime, 'image/') ? 'photo' : 'video';

    $result = telegramApi($endpoint, [
        'chat_id' => $config['telegram']['chat_id'],
        $field => new CURLFile($file['tmp_name'], $mime, $file['name']),
    ], $config['telegram']['bot_token']);

    if (!$result['success']) {
        if ($endpoint === 'sendPhoto') {
            $result = telegramApi('sendDocument', [
                'chat_id' => $config['telegram']['chat_id'],
                'document' => new CURLFile($file['tmp_name'], $mime, $file['name']),
            ], $config['telegram']['bot_token']);
        }
        if (!$result['success']) {
            jsonResponse(['success' => false, 'error' => $result['error']], 502);
        }
    }

    $data = $result['data']['result'] ?? [];
    $fileId = $data['document']['file_id'] ?? $data['video']['file_id'] ?? (($data['photo'] ?? [])[count($data['photo'] ?? []) - 1]['file_id'] ?? null);

    if (!$fileId) {
        jsonResponse(['success' => false, 'error' => 'Failed to resolve Telegram file id'], 500);
    }

    $encodedId = encodeFileId($fileId);
    $origin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $fileUrl = $origin . '/file/' . $encodedId;
    $now = (int) round(microtime(true) * 1000);

    saveUploadMetadata([
        'encodedId' => $encodedId,
        'fileId' => $fileId,
        'originalName' => $file['name'],
        'fileType' => $mime,
        'size' => (int)$file['size'],
        'uploadedAt' => $now,
    ]);

    saveUsageStats($fingerprint, [
        'fileName' => $file['name'],
        'fileType' => $mime,
        'bytes' => (int)$file['size'],
        'viaApiKey' => (bool)$apiKeyRecord,
    ], $rate);

    if ($apiKeyRecord) {
        touchApiKey($apiKeyRecord['key']);
    }

    jsonResponse([
        'success' => true,
        'url' => $fileUrl,
        'fileId' => $fileId,
        'encodedFileId' => $encodedId,
        'originalName' => $file['name'],
        'size' => (int)$file['size'],
        'fileType' => $mime,
        'uploadedAt' => $now,
        'viaApiKey' => (bool)$apiKeyRecord,
    ]);
}

if (preg_match('#^/file/([A-Za-z0-9_-]+)$#', $uri, $m) && $method === 'GET') {
    $encodedId = $m[1];
    $fileId = decodeFileId($encodedId);
    if (!$fileId) {
        http_response_code(400);
        echo 'Invalid file reference';
        exit;
    }

    $infoUrl = sprintf('https://api.telegram.org/bot%s/getFile?file_id=%s', $config['telegram']['bot_token'], urlencode($fileId));
    $infoRaw = @file_get_contents($infoUrl);
    $info = $infoRaw ? json_decode($infoRaw, true) : null;
    if (!($info['ok'] ?? false) || empty($info['result']['file_path'])) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    $meta = findUploadMetadata($encodedId);
    $path = $info['result']['file_path'];
    $name = $meta['originalName'] ?? basename($path);
    $mime = $meta['fileType'] ?? guessMime($name);
    $size = (int)($meta['size'] ?? ($info['result']['file_size'] ?? 0));
    $uploadedAt = (int)($meta['uploadedAt'] ?? round(microtime(true) * 1000));

    if (($_GET['info'] ?? '') === 'true') {
        $origin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        jsonResponse([
            'success' => true,
            'fileId' => $fileId,
            'encodedFileId' => $encodedId,
            'url' => $origin . '/file/' . $encodedId,
            'originalName' => $name,
            'fileType' => $mime,
            'size' => $size,
            'uploadedAt' => $uploadedAt,
        ]);
    }

    if (($_GET['a'] ?? '') === 'view') {
        readfile(__DIR__ . '/index.html');
        exit;
    }

    header('Cache-Control: public, max-age=31536000');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Type: ' . $mime);

    $streamUrl = sprintf('https://api.telegram.org/file/bot%s/%s', $config['telegram']['bot_token'], $path);
    readfile($streamUrl);
    exit;
}

if ($uri === '/' || in_array($uri, ['/admin', '/docs', '/about'], true)) {
    readfile(__DIR__ . '/index.html');
    exit;
}

$asset = __DIR__ . $uri;
if (is_file($asset)) {
    $ext = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
    }
    readfile($asset);
    exit;
}

http_response_code(404);
echo 'Not found';
