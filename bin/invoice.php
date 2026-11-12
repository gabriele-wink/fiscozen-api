<?php
// Crea una fattura per un cliente (per P.IVA o CF) e salva il PDF. Non la invia allo SDI salvo --send.
//
//   php bin/invoice.php <piva|cf> "<descrizione>" <importo> [--dry-run] [--send]
//
//   --dry-run  calcola soltanto (compute_data): nessuna fattura creata
//   --send     dopo la creazione la invia allo SDI. NON si può annullare.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscozenApi\Fiscozen;

chdir(dirname(__DIR__));
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$flags = array_filter($argv, fn($a) => str_starts_with($a, '--'));
if (count($args) < 3) {
    fwrite(STDERR, "Uso: php bin/invoice.php <piva|cf> \"<descrizione>\" <importo> [--dry-run] [--send]\n");
    exit(2);
}
[$taxId, $description, $amount] = $args;

$fz = new Fiscozen('.session.json');
if (!$fz->isAuthenticated()) {
    exit("Sessione scaduta: esegui php bin/login.php\n");
}

$customer = $fz->findCustomer($taxId) ?? exit("Cliente $taxId non trovato: crealo con createCustomer()\n");
$method = array_values(array_filter($fz->invoicePaymentMethods(), fn($m) => $m['method'] === 'Bonifico'))[0]['id'] ?? null;
$payload = $fz->buildInvoice((int)$customer['counterpart_version'],
    [['description' => $description, 'amount' => (float)$amount]], ['payment_method' => $method]);

$preview = $fz->computeInvoice($payload);
printf("Cliente: %s\nNumero: %s  Totale: %s %s\nNote: %s\n", $customer['display_name'], $preview['full_id'],
    $preview['total_amount'], $preview['currency_code'], implode(' | ', $preview['all_notes'] ?? []));

if (in_array('--dry-run', $flags, true)) {
    exit(0);
}

$inv = $fz->createInvoice($payload);
$file = 'fattura_' . str_replace('/', '-', $inv['full_id']) . '.pdf';
file_put_contents($file, $fz->invoicePdf((int)$inv['id']));
echo "Creata fattura {$inv['full_id']} (id {$inv['id']}), PDF: $file\n";

if (in_array('--send', $flags, true)) {
    $fz->sendInvoiceToSdi((int)$inv['id']);
    echo "Inviata allo SDI\n";
}
