<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function corsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    corsHeaders();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requireAuth(array $admin): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Basic ')) {
        unauthorized();
    }

    $decoded = base64_decode(substr($header, 6), true);
    if (!$decoded || !str_contains($decoded, ':')) {
        unauthorized();
    }

    [$username, $password] = explode(':', $decoded, 2);
    if ($username !== $admin['username'] || $password !== $admin['password']) {
        unauthorized();
    }
}

function unauthorized(): void
{
    header('WWW-Authenticate: Basic realm="Dashboard"');
    jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

function encodeFileId(string $fileId): string
{
    return rtrim(strtr(base64_encode($fileId), '+/', '-_'), '=');
}

function decodeFileId(string $encoded): ?string
{
    $padded = str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) + (4 - strlen($encoded) % 4) % 4, '=', STR_PAD_RIGHT);
    $decoded = base64_decode($padded, true);

    return $decoded === false ? null : $decoded;
}

function guessMime(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
    ][$ext] ?? 'application/octet-stream';
}

function fingerprintIdentity(): array
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '??';
    $device = $_SERVER['HTTP_SEC_CH_UA_MODEL'] ?? 'unknown';
    $browser = $_SERVER['HTTP_SEC_CH_UA'] ?? 'unknown';

    $identity = hash('sha256', implode('|', [$ip, $userAgent, $country, $device, $browser]));

    return [
        'identity' => $identity,
        'ipHash' => substr(hash('sha256', $ip), 0, 24),
        'userAgent' => $userAgent,
        'country' => $country,
        'device' => $device,
        'browser' => $browser,
    ];
}

function telegramApi(string $method, array $fields, string $token): array
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($err || !$raw) {
        return ['success' => false, 'error' => $err ?: 'Telegram request failed'];
    }

    $json = json_decode($raw, true);
    if ($status >= 400 || !($json['ok'] ?? false)) {
        return ['success' => false, 'error' => $json['description'] ?? 'Telegram upload failed'];
    }

    return ['success' => true, 'data' => $json];
}
