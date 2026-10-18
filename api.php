<?php
/**
 * Demo Tablero SQDCE · TalentoIA
 * Puente entre la página y la API de Claude. La API key vive en private/config.php
 * y nunca llega al navegador.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Configuración: private/config.php (hosting) o variables de entorno (Docker en VPS)
$cfgFile = __DIR__ . '/private/config.php';
$cfg = is_file($cfgFile) ? require $cfgFile : [];
foreach (['anthropic_api_key' => 'ANTHROPIC_API_KEY', 'model' => 'CLAUDE_MODEL', 'demo_key' => 'DEMO_KEY',
          'max_por_ip_hora' => 'MAX_POR_IP_HORA', 'max_por_dia' => 'MAX_POR_DIA'] as $k => $env) {
    $v = getenv($env);
    if ($v !== false && $v !== '') $cfg[$k] = $v;
}
if (empty($cfg['anthropic_api_key'])) out(['error' => 'Falta la API key (private/config.php o variable ANTHROPIC_API_KEY).'], 500);
$apiKey = (string)($cfg['anthropic_api_key'] ?? '');
if ($apiKey === '' || str_contains($apiKey, 'PEGA_AQUI')) out(['error' => 'Falta la API key en private/config.php.'], 500);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Método no permitido.'], 405);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 40000) out(['error' => 'Solicitud demasiado grande.'], 413);

// Clave opcional de demo: si config.php define demo_key, la página debe abrirse con ?k=esa-clave
$demoKey = (string)($cfg['demo_key'] ?? '');
if ($demoKey !== '' && !hash_equals($demoKey, (string)($_SERVER['HTTP_X_DEMO_KEY'] ?? ''))) {
    out(['error' => 'Acceso no autorizado.'], 403);
}

// Límite de uso por IP y diario (protege tu saldo de API)
function rateOk(string $bucket, int $max, int $window): bool {
    $dir = __DIR__ . '/private/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return true; // si no se puede escribir, no bloquea la demo
    $file = $dir . '/' . hash('sha256', $bucket) . '.json';
    $now = time();
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $hits = json_decode((string)stream_get_contents($fp), true);
    $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $window));
    $ok = count($hits) < $max;
    if ($ok) $hits[] = $now;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'x';
if (!rateOk('ip:' . $ip, (int)($cfg['max_por_ip_hora'] ?? 30), 3600)) out(['error' => 'Demasiadas consultas. Intenta más tarde.'], 429);
if (!rateOk('global', (int)($cfg['max_por_dia'] ?? 300), 86400)) out(['error' => 'Se alcanzó el límite diario de la demo.'], 429);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) out(['error' => 'Datos no válidos.'], 400);
$mode = ($body['mode'] ?? '') === 'libre' ? 'libre' : 'causas';
$question = trim(mb_substr((string)($body['question'] ?? ''), 0, 400));
$context = trim(mb_substr((string)($body['context'] ?? ''), 0, 12000));
if ($question === '' || $context === '') out(['error' => 'Faltan la pregunta o los datos del tablero.'], 400);

$system = 'Eres el agente de un prototipo de tablero SQDCE de TalentoIA, usado como demostración con datos de una planta ficticia (Corasa). '
        . 'Solo respondes preguntas sobre la operación, los indicadores, las causas y las acciones de esa planta, usando los datos que te dan. '
        . 'Si te piden otra cosa, responde en una línea que solo puedes ayudar con el tablero de la planta. '
        . 'Responde siempre en español de México, directo y práctico. No inventes cifras: cita solo evidencia de los datos o de las notas.';

if ($mode === 'causas') {
    $instr = "Responde para leerse en una pantalla. Usa EXACTAMENTE este formato, con las cuatro secciones en este orden y 1 viñeta por sección (2 como máximo):\n"
           . "## Método\n- **Hipótesis corta.** *Evidencia:* dato o nota concreta que la sostiene. *Verificar:* qué revisar en piso.\n"
           . "## Máquina\n- ...\n## Material\n- ...\n## Mano de obra\n- ...\n"
           . "Son hipótesis para verificar en piso, no conclusiones.\n\n"
           . "No agregues introducción ni otras secciones. Máximo 170 palabras.";
    $maxTokens = 1200;
} else {
    $instr = 'Responde en máximo 90 palabras.';
    $maxTokens = 700;
}
$user = $context . "\n\nPREGUNTA DEL SUPERVISOR: " . $question . "\n\n" . $instr;

$payload = [
    'model' => (string)($cfg['model'] ?? 'claude-haiku-5-5'),
    'max_tokens' => $maxTokens,
    'system' => $system,
    'messages' => [['role' => 'user', 'content' => $user]],
    'thinking' => ['type' => 'disabled'], // respuesta directa: la demo necesita velocidad
];

function callClaude(array $payload, string $apiKey): array {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$resp, $status, $err];
}

[$resp, $status, $err] = callClaude($payload, $apiKey);
// Si el modelo no acepta el parámetro thinking, reintenta sin él
if ($status === 400 && is_string($resp) && stripos($resp, 'thinking') !== false) {
    unset($payload['thinking']);
    $payload['max_tokens'] = $maxTokens + 2000;
    [$resp, $status, $err] = callClaude($payload, $apiKey);
}

if ($resp === false) out(['error' => 'No se pudo conectar con Claude: ' . $err], 502);
$j = json_decode((string)$resp, true);
if ($status !== 200) {
    $msg = is_array($j) ? (string)($j['error']['message'] ?? 'Error de la API') : 'Error de la API';
    out(['error' => 'Claude respondió ' . $status . ': ' . $msg], 502);
}
$text = '';
$types = [];
foreach (($j['content'] ?? []) as $block) {
    $types[] = (string)($block['type'] ?? '?');
    if (($block['type'] ?? '') === 'text') $text .= (string)$block['text'];
}
if (trim($text) === '') {
    out(['error' => 'Respuesta vacía (modelo: ' . ($j['model'] ?? '?') . ', stop_reason: ' . ($j['stop_reason'] ?? '?')
        . ', bloques: ' . (implode(',', $types) ?: 'ninguno') . ')'], 502);
}
out(['text' => $text]);
