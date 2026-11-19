<?php
// Debug script to check environment variables
header('Content-Type: application/json');

$apiKey = getenv('OPENAI_API_KEY');
$model = getenv('OPENAI_MODEL');
$corsOrigin = getenv('CORS_ORIGIN');

echo json_encode([
    'OPENAI_API_KEY' => $apiKey ? 'SET (' . substr($apiKey, 0, 20) . '...)' : 'NOT SET',
    'OPENAI_MODEL' => $model ?: 'NOT SET',
    'CORS_ORIGIN' => $corsOrigin ?: 'NOT SET',
    'PHP_VERSION' => phpversion(),
    'All ENV' => array_keys(array_filter($_SERVER, function($k) { return strpos($k, 'OPENAI') !== false; }, ARRAY_FILTER_USE_KEY))
], JSON_PRETTY_PRINT);
?>
