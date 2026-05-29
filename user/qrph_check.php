<?php
require '../config.php';
header('Content-Type: application/json');

$intent_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['intent_id'] ?? '');
if (!$intent_id) { echo json_encode(['status' => 'error']); exit; }

$sk = $_ENV['PAYMONGO_SECRET_KEY'];

$ch = curl_init("https://api.paymongo.com/v1/payment_intents/{$intent_id}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode($sk . ':')],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res    = json_decode(curl_exec($ch), true);
curl_close($ch);

$status = $res['data']['attributes']['status'] ?? 'unknown';
echo json_encode(['status' => $status]);