<?php
declare(strict_types=1);
/** Safe CLI preflight: reads only the automation configuration; never sends. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$config = require __DIR__ . '/royal_ai_sales_auto_config.php';
$errors = [];
if (($config['auto_enabled'] ?? null) !== false) $errors[] = 'Global switch must remain off during preflight';
if (($config['company_id'] ?? null) !== 1) $errors[] = 'Unexpected company';
foreach (($config['agents'] ?? []) as $id => $agent) {
    if (($agent['auto_enabled'] ?? null) !== false) $errors[] = "Agent $id must remain off";
    foreach (($agent['allowed_phones'] ?? []) as $phone) {
        if (!preg_match('/^504[0-9]{8}$/', (string)$phone)) $errors[] = "Invalid phone in agent $id";
    }
}
if (($config['agents'][1]['instance_id'] ?? '') !== '3F8EA469A3BB11D54983B63E388305A1') $errors[] = 'Carlos instance mismatch';
echo json_encode([
    'ok' => !$errors,
    'mode' => 'preflight_only',
    'auto_enabled' => false,
    'configured_agents' => count($config['agents'] ?? []),
    'checks' => $errors ?: ['Config readable', 'All send switches off', 'Carlos instance pinned', 'Test allowlist valid'],
    'sends' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors ? 1 : 0);