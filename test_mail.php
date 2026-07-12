<?php
$apiKey = getenv('RESEND_API_KEY');

// Show first 10 chars of key to verify it's loading
echo 'API Key loaded: ' . (empty($apiKey) ? 'NO - KEY IS EMPTY!' : 'YES - starts with: ' . substr($apiKey, 0, 10) . '...') . '<br>';

$payload = json_encode([
    'from'    => 'Jeeplify BCD <onboarding@resend.dev>',
    'to'      => ['itsmejoeven18@gmail.com'],
    'subject' => 'Jeeplify Test Email',
    'text'    => 'If you see this, Resend is working!',
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo 'HTTP Code: ' . $httpCode . '<br>';
echo 'Response: ' . $response;
?>