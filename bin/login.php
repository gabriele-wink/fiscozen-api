<?php
// Usage:
//   php bin/login.php            -> sends the SMS code (if session not valid)
//   php bin/login.php 123456     -> submits the SMS code, saves session to .session.json

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscozenApi\FiscozenClient;

chdir(dirname(__DIR__));
[$fz, $email, $password] = FiscozenClient::fromCredentialsFile('.credentials.txt', '.session.json');

if (isset($argv[1])) {
    $fz->verifyMfa($argv[1]);
    echo "OK: autenticato, sessione salvata in .session.json\n";
    exit(0);
}
if ($fz->isAuthenticated()) {
    echo "Sessione già valida\n";
    exit(0);
}
$r = $fz->login($email, $password);
if ($r['authenticated']) {
    echo "OK: autenticato senza MFA\n";
} elseif ($r['mfa_required']) {
    echo 'MFA richiesta (' . implode(',', $r['mfa_types']) . "): SMS inviato. Esegui: php bin/login.php <codice>\n";
} else {
    fwrite(STDERR, 'Login fallito: ' . json_encode($r['raw']['errors'] ?? $r['raw']) . "\n");
    exit(1);
}
