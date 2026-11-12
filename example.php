<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use FiscozenApi\Fiscozen;

$fz = new Fiscozen(__DIR__ . '/.session.json');
if (!$fz->isAuthenticated()) {
    exit("Sessione scaduta: esegui php bin/login.php\n");
}

$stats = $fz->yearStats();
printf("Fatturato %d: %s EUR\n\n", $stats['fiscal_year'], $stats['year_turnover']);

echo "Fatture emesse:\n";
foreach ($fz->allInvoices() as $inv) {
    printf("  %-8s %s  %10s EUR  %s\n", $inv['full_id'], $inv['invoice_date'], $inv['amount'],
        $inv['counterpart_version_display_name']);
}

echo "\nProssime scadenze F24:\n";
foreach ($fz->taxPayments() as $p) {
    printf("  %s  %10s EUR  %s\n", $p['due_date'], $p['amount'], $p['description']);
}
