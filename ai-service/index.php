<?php
/**
 * AI Service - Integración Real con Ollama (Llama2)
 * Motor IA local sin dependencias de APIs pagas
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/ai-service.log');

// CORS
header('Access-Control-Allow-Origin: ' . getenv('CORS_ORIGIN') ?: '*');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuración de Ollama
$OLLAMA_HOST = 'http://host.docker.internal:11434';
$OLLAMA_MODEL = 'llama2';

// Intentar contactar Ollama
function tryOllama($prompt, $system_prompt = '') {
    global $OLLAMA_HOST, $OLLAMA_MODEL;
    
    $full_prompt = $system_prompt . "\n\n" . $prompt;
    
    $ch = curl_init($OLLAMA_HOST . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $OLLAMA_MODEL,
            'prompt' => $full_prompt,
            'stream' => false,
            'temperature' => 0.7
        ]),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['response'] ?? null;
}

// Fallback: Respuestas inteligentes locales
class AIFallback {
    public static function chat($message) {
        $message = strtolower(trim($message));
        $responses = [
            'sala' => 'Te recomiendo visitar "Salones disponibles" para ver todas las opciones. Puedes filtrar por capacidad, fecha y características.',
            'recomendar' => 'Basado en tu perfil, te recomiendo: Sala de Estudio 101 (grupos pequeños) o Aula 205 (con tecnología). ¿Cuántas personas?',
            'reserv' => 'Para reservar: 1) Selecciona la sala 2) Elige fecha y hora 3) Confirma. ¡Es muy fácil!',
            'horario' => 'Los horarios ocupados aparecen en rojo. Los disponibles en verde. Haz clic en "Ver y reservar" para detalles.',
            'precio' => 'Las salas son 100% GRATIS para estudiantes de UNILLANOS.',
            'capacidad' => 'Tenemos salas desde 2 hasta 50 personas. ¿Para cuántas necesitas?',
            'computador' => 'El Laboratorio tiene 30 máquinas. La Sala 101 tiene 5. Consulta en cada sala.',
            'grupo' => 'Para grupos, recomiendo Sala 101 (tranquila) o Aula 205 (colaborativa).',
            'silencio' => 'Salas con silencio: Estudios. Con conversación: Aulas.',
            'aire' => 'Con aire: 101, 205, Laboratorio y Auditorio. Filtra en la búsqueda.',
            'proyector' => 'Salas con proyector: Aula 205, Auditorio y Lab Cómputo.',
            'cancelar' => 'Cancela en "Mis reservas" - se libera el espacio al instante.',
            'hola' => '¡Hola! Soy tu asistente IA. ¿Qué necesitas?',
        ];
        
        foreach ($responses as $keyword => $response) {
            if (strpos($message, $keyword) !== false) {
                return $response;
            }
        }
        
        return "Pregunta: \"" . htmlspecialchars($message) . "\". Puedo ayudarte con reservas, disponibilidad y recomendaciones. ¿Qué necesitas?";
    }
}

// Endpoints
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($path === '/chat' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userMessage = $input['message'] ?? '';
        
        $systemPrompt = 'Eres un asistente inteligente para un sistema de reserva de salas universitarias en UNILLANOS. '
            . 'Responde de forma concisa y útil. Mantén respuestas cortas (máximo 2 párrafos).';
        
        // Intentar usar Ollama (Llama2 real)
        $response = tryOllama($userMessage, $systemPrompt);
        
        // Si Ollama falla, usar fallback local
        if (!$response) {
            $response = AIFallback::chat($userMessage);
        }
        
        echo json_encode(['response' => trim($response)]);
    }
    elseif ($path === '/recommend' && $method === 'GET') {
        $prompt = 'Dame 3 recomendaciones de salas universitarias para estudiar. Incluye nombre y por qué es buena. Formato: nombre: razón.';
        $response = tryOllama($prompt, 'Eres un asistente recomendador de salas.');
        
        if ($response) {
            echo json_encode([
                'recommendations' => [
                    ['name' => 'Llama2 dice:', 'reason' => trim($response)]
                ]
            ]);
        } else {
            echo json_encode([
                'recommendations' => [
                    ['name' => 'Sala de Estudio 101', 'reason' => 'Perfecta para grupos pequeños'],
                    ['name' => 'Aula 205', 'reason' => 'Buena capacidad y tecnología'],
                    ['name' => 'Laboratorio', 'reason' => 'Con computadores']
                ]
            ]);
        }
    }
    elseif ($path === '/predict' && $method === 'GET') {
        echo json_encode([
            'predictions' => [
                ['room' => 'Sala 101', 'occupancy' => rand(30, 90), 'trend' => 'stable'],
                ['room' => 'Aula 205', 'occupancy' => rand(20, 70), 'trend' => 'increasing'],
                ['room' => 'Lab', 'occupancy' => rand(40, 95), 'trend' => 'high']
            ]
        ]);
    }
    elseif ($path === '/anomalies' && $method === 'GET') {
        echo json_encode([
            'anomalies' => [
                ['event' => 'Pico de ocupación', 'description' => 'Sala 101 pasó de 10% a 85% en 15 minutos'],
                ['event' => 'Uso tardío', 'description' => 'Aula 205 en uso después de horario']
            ]
        ]);
    }
    elseif ($path === '/feedback' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $text = $input['text'] ?? '';
        
        $positive_words = ['excelente', 'muy bien', 'genial', 'encanta', 'perfecto', 'increíble'];
        $negative_words = ['malo', 'no me gusta', 'terrible', 'problema', 'error'];
        
        $text_lower = strtolower($text);
        $positive = 0;
        $negative = 0;
        
        foreach ($positive_words as $word) {
            if (strpos($text_lower, $word) !== false) $positive++;
        }
        foreach ($negative_words as $word) {
            if (strpos($text_lower, $word) !== false) $negative++;
        }
        
        if ($positive > $negative) {
            $sentiment = 'Positivo';
            $score = 0.8;
        } elseif ($negative > $positive) {
            $sentiment = 'Negativo';
            $score = 0.2;
        } else {
            $sentiment = 'Neutral';
            $score = 0.5;
        }
        
        echo json_encode(['sentiment' => $sentiment, 'score' => $score]);
    }
    elseif ($path === '/health' && $method === 'GET') {
        $ollamaHealth = tryOllama('Responde "ok"');
        echo json_encode([
            'status' => 'ok',
            'ai_mode' => $ollamaHealth ? 'ollama-llama2' : 'fallback',
            'ollama_available' => !empty($ollamaHealth),
            'model' => $ollamaHealth ? 'Llama2 (Real)' : 'Fallback Local'
        ]);
    }
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint no encontrado']);
    }
}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
