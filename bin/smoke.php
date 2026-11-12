<?php
// Read-only smoke test of every Fiscozen method. Prints counts/keys only, no personal data.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FiscozenApi\Fiscozen;

chdir(dirname(__DIR__));
$fz = new Fiscozen('.session.json');

$count = fn(iterable $g) => is_array($g) ? count($g) : iterator_count($g);
$tests = [
    'me' => fn() => count($fz->me()) . ' fields',
    'subscriptions' => fn() => count($fz->subscriptions()),
    'extraServices' => fn() => count($fz->extraServices()),
    'featureFlags' => fn() => count($fz->featureFlags()),
    'addresses' => fn() => count($fz->addresses()),
    'fiscalRegimes' => fn() => count($fz->fiscalRegimes()),
    'yearStats' => fn() => implode(',', array_slice(array_keys((array)$fz->yearStats()), 0, 6)),
    'listInvoices' => fn() => $fz->listInvoices()['count'] . ' total',
    'allInvoices' => fn() => $count($fz->allInvoices()),
    'invoicesToCollect' => fn() => $count($fz->allInvoices(Fiscozen::SECTION_TO_COLLECT)),
    'invoice+pdf+xml' => function () use ($fz) {
        $first = $fz->listInvoices(pageSize: 1)['results'][0];
        $inv = $fz->invoice($first['id']);
        $pdf = $fz->invoicePdf($first['id']);
        $xml = $inv['is_e_invoice'] ? strlen($fz->invoiceXml($first['id'])) . 'B xml' : 'no e-invoice';
        return "invoice {$inv['full_id']}, pdf " . strlen($pdf) . 'B ' . (str_starts_with($pdf, '%PDF') ? 'ok' : 'NOT PDF') . ", $xml";
    },
    'invoiceNumbering' => fn() => json_encode($fz->invoiceNumbering()),
    'invoicingSettings' => fn() => count($fz->invoicingSettings()),
    'invoicePaymentMethods' => fn() => count($fz->invoicePaymentMethods()),
    'allCreditNotes' => fn() => $count($fz->allCreditNotes()),
    'creditNotePdf' => function () use ($fz) {
        $cn = $fz->listCreditNotes(pageSize: 1)['results'][0] ?? null;
        if (!$cn) return 'none';
        $fz->creditNote($cn['id']);
        $pdf = $fz->creditNotePdf($cn['id']);
        return strlen($pdf) . 'B ' . (str_starts_with($pdf, '%PDF') ? 'ok' : 'NOT PDF');
    },
    'allReceipts' => fn() => $count($fz->allReceipts()),
    'allExpenses' => fn() => $count($fz->allExpenses()),
    'expense' => function () use ($fz) {
        $e = $fz->listExpenses(pageSize: 1)['results'][0] ?? null;
        return $e ? count($fz->expense($e['id'])) . ' fields' : 'none';
    },
    'allCustomers' => fn() => $count($fz->allCustomers()),
    'allProviders' => fn() => $count($fz->allProviders()),
    'taxPayments' => fn() => count($fz->taxPayments()) . ' upcoming / ' . count($fz->taxPayments(true)) . ' incl. past',
    'taxForecasts' => fn() => count($fz->taxForecasts()),
    'taxDeclarations' => fn() => gettype($fz->taxDeclarations()),
    'allDocuments' => fn() => $count($fz->allDocuments()),
    'downloadDocument' => function () use ($fz) {
        foreach ($fz->allDocuments() as $d) {
            return strlen($fz->downloadDocument($d)) . 'B (' . pathinfo($d['file_name_ext'], PATHINFO_EXTENSION) . ')';
        }
        return 'none';
    },
    'allNotifications' => fn() => $count($fz->allNotifications()),
];

$fail = 0;
foreach ($tests as $name => $t) {
    try {
        printf("OK   %-22s %s\n", $name, $t());
    } catch (Throwable $e) {
        $fail++;
        printf("FAIL %-22s %s %s\n", $name, $e->getMessage(), json_encode($e->payload ?? null));
    }
}
exit($fail ? 1 : 0);
