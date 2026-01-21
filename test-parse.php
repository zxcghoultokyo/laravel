<?php
$text = 'Для куртки ECWCS Gen III Level 7 при твоїх 178/110 зазвичай підходить XL або XXL, але в картці немає точної сітки розмірів.';

// Test parseStructuredResponse logic
$json = null;
if (preg_match('/\{[\s\S]*\}/u', $text, $matches)) {
    $json = json_decode($matches[0], true);
    echo "JSON match found: " . ($json ? print_r($json, true) : 'null') . PHP_EOL;
} else {
    echo "No JSON match" . PHP_EOL;
}

if ((!$json || !isset($json['products'])) && preg_match('/\[[\s\S]*\]/u', $text, $arrayMatches)) {
    echo "Array match found: " . print_r($arrayMatches, true) . PHP_EOL;
} else {
    echo "No array match" . PHP_EOL;
}

echo "Test complete" . PHP_EOL;
