<?php

declare(strict_types=1);

namespace FiscozenApi\Mcp;

use FiscozenApi\Fiscozen;
use FiscozenApi\FiscozenAuthException;

/**
 * Registers the Fiscozen tools on an McpServer.
 *
 * Configuration (environment variables):
 *   FISCOZEN_SESSION_FILE      cookie/session file (default: <project>/.session.json)
 *   FISCOZEN_EMAIL / FISCOZEN_PASSWORD, or FISCOZEN_CREDENTIALS_FILE (line 1 email, line 2 password)
 *   FISCOZEN_MCP_READONLY=1    expose read-only tools only
 *   FISCOZEN_MCP_ALLOW_SDI=1   expose send_invoice_to_sdi (irreversible tax submission)
 */
class FiscozenTools
{
    private ?Fiscozen $fz = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public static function server(string $projectDir): McpServer
    {
        $server = new McpServer('fiscozen', '1.0.0', implode("\n", [
            'Unofficial tools for Fiscozen (Italian VAT / partita IVA management): invoices, customers, expenses, taxes, documents.',
            'Amounts are EUR strings. Dates are YYYY-MM-DD.',
            'If a tool says the session expired: call login_start (sends an SMS to the account owner), ask the user for the code, then login_verify.',
            'To issue an invoice: find_customer or create_customer -> preview_invoice -> create_invoice (numbered, NOT sent) -> send_invoice_to_sdi only after explicit user confirmation.',
        ]));
        (new self($projectDir))->register($server);
        return $server;
    }

    private function fz(): Fiscozen
    {
        return $this->fz ??= new Fiscozen(getenv('FISCOZEN_SESSION_FILE') ?: $this->projectDir . '/.session.json');
    }

    /** Ensure an authenticated session, with an actionable message for the model otherwise. */
    private function authed(): Fiscozen
    {
        $fz = $this->fz();
        if (!$fz->isAuthenticated()) {
            throw new FiscozenAuthException('Fiscozen session expired or missing. Call login_start, ask the user for the SMS code, then call login_verify.');
        }
        return $fz;
    }

    private function credentials(): array
    {
        $email = getenv('FISCOZEN_EMAIL');
        $password = getenv('FISCOZEN_PASSWORD');
        if ($email && $password) {
            return [$email, $password];
        }
        $file = getenv('FISCOZEN_CREDENTIALS_FILE') ?: $this->projectDir . '/.credentials.txt';
        if (!is_readable($file)) {
            throw new \RuntimeException('No credentials: set FISCOZEN_EMAIL/FISCOZEN_PASSWORD or FISCOZEN_CREDENTIALS_FILE');
        }
        $lines = array_map('trim', file($file, FILE_IGNORE_NEW_LINES));
        return [$lines[0], $lines[1]];
    }

    // ----------------------------------------------------------- schemas

    private const RO = ['readOnlyHint' => true, 'openWorldHint' => true];
    private const WRITE = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true];
    private const DESTRUCTIVE = ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true];

    private static function obj(array $props, array $required = []): array
    {
        $s = ['type' => 'object', 'properties' => $props ?: new \stdClass()];
        if ($required) {
            $s['required'] = $required;
        }
        return $s;
    }

    private static function int(string $d): array { return ['type' => 'integer', 'description' => $d]; }
    private static function str(string $d): array { return ['type' => 'string', 'description' => $d]; }
    private static function bool(string $d): array { return ['type' => 'boolean', 'description' => $d]; }

    private static function rowsSchema(): array
    {
        return ['type' => 'array', 'minItems' => 1, 'description' => 'Invoice lines', 'items' => self::obj([
            'description' => self::str('Line description'),
            'amount' => ['type' => 'number', 'description' => 'Unit price in EUR (VAT excluded)'],
            'quantity' => ['type' => 'number', 'description' => 'Quantity, default 1'],
            'vat_code' => self::str('VAT/nature code, e.g. NI3, VAT22, RC02 (see list_vat_codes). Default: from fiscal regime'),
        ], ['description', 'amount'])];
    }

    private static function invoiceProps(): array
    {
        return [
            'customer_tax_id' => self::str('Customer VAT number or tax code (alternative to counterpart_version)'),
            'counterpart_version' => self::int('Customer version id (from find_customer/get_customer/create_customer)'),
            'rows' => self::rowsSchema(),
            'invoice_date' => self::str('YYYY-MM-DD, default today'),
            'payment_method_id' => self::int('Collection method id (see list_payment_methods). Default: first "Bonifico"'),
            'payment_due_date' => self::str('YYYY-MM-DD'),
            'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Extra notes printed on the invoice'],
            'is_e_invoice' => self::bool('Electronic invoice (FatturaPA), default true'),
            'e_doc_data' => ['type' => 'object', 'description' => 'Extra FatturaPA data: cig_code, cup_code, pec, recipient_code...'],
        ];
    }

    private static function customerProps(): array
    {
        return [
            'typology' => ['type' => 'string', 'enum' => ['PRIV', 'PROF', 'SOCI', 'PA', 'NOPR'],
                'description' => 'PRIV private person, PROF professional, SOCI company, PA public administration, NOPR non-profit'],
            'business_name' => self::str('Company name (SOCI/PA/NOPR)'),
            'first_name' => self::str('First name (PRIV/PROF)'),
            'last_name' => self::str('Last name (PRIV/PROF)'),
            'vat' => self::str('VAT number (required for Italian companies)'),
            'ssn' => self::str('Italian tax code / codice fiscale (required for Italian private persons)'),
            'address' => self::str('Street and number'),
            'municipality' => self::str('City'),
            'postcode' => self::str('Postal code'),
            'province' => self::str('Province code, e.g. MI (Italy)'),
            'country' => self::str('ISO 3166-1 alpha-2, default IT'),
            'recipient_code' => self::str('SDI recipient code, 7 chars (0000000 if unknown, XXXXXXX for foreign customers)'),
            'pec' => self::str('Certified email (PEC)'),
            'email' => self::str('Email'),
            'phone' => self::str('Phone'),
        ];
    }

    // ---------------------------------------------------------- register

    public function register(McpServer $s): void
    {
        $readonly = getenv('FISCOZEN_MCP_READONLY') === '1';

        // auth
        $s->tool('auth_status', 'Check whether the Fiscozen session is valid.', self::obj([]),
            fn() => ['authenticated' => $this->fz()->isAuthenticated()], self::RO);
        $s->tool('login_start', 'Start Fiscozen login with the configured credentials. If 2FA is enabled an SMS code is sent to the account owner: ask the user for it and call login_verify.',
            self::obj([]), function () {
                [$email, $password] = $this->credentials();
                $r = $this->fz()->login($email, $password);
                return ['authenticated' => $r['authenticated'], 'mfa_required' => $r['mfa_required'], 'mfa_types' => $r['mfa_types']];
            }, self::WRITE);
        $s->tool('login_verify', 'Complete login with the SMS code received by the user.', self::obj(['code' => self::str('SMS code')], ['code']),
            fn(array $a) => ['authenticated' => $this->fz()->verifyMfa((string)$a['code'])['authenticated']], self::WRITE);

        // account
        $s->tool('get_profile', 'Business profile: name, VAT number, fiscal regime, ATECO codes, addresses.', self::obj([]),
            fn() => array_intersect_key($this->authed()->me(), array_flip([
                'full_name', 'business_name', 'vat', 'ssn', 'email', 'pec', 'fiscal_regime_full', 'tax_coeff',
                'vat_ateco_1', 'vat_ateco_1_description', 'vat_ateco_2', 'business_start', 'full_business_address',
                'welfare_institution_name', 'active_subscription', 'is_2fa_enabled',
            ])), self::RO);
        $s->tool('get_year_stats', 'Real-time turnover and revenue for a fiscal year, vs. the flat-rate regime ceiling.',
            self::obj(['year' => self::int('Fiscal year, default current')]),
            fn(array $a) => $this->authed()->yearStats($a['year'] ?? null), self::RO);

        // invoices (read)
        $s->tool('list_invoices', 'List issued invoices (one page, newest first).', self::obj([
            'section' => ['type' => 'string', 'enum' => ['ARCHIVIO', 'DA_INCASSARE', 'DA_INVIARE'],
                'description' => 'ARCHIVIO all, DA_INCASSARE unpaid, DA_INVIARE not yet sent to SDI. Default ARCHIVIO'],
            'page' => self::int('Page, default 1'), 'page_size' => self::int('Default 50, max 100'),
        ]), fn(array $a) => $this->compactPage($this->authed()->listInvoices(
            $a['section'] ?? Fiscozen::SECTION_ARCHIVE, (int)($a['page'] ?? 1), min(100, (int)($a['page_size'] ?? 50)))), self::RO);
        $s->tool('get_invoice', 'Full invoice detail: lines, totals, customer, SDI status.', self::obj(['id' => self::int('Invoice id')], ['id']),
            fn(array $a) => $this->authed()->invoice((int)$a['id']), self::RO);
        $s->tool('get_invoice_pdf', 'Download the invoice PDF (returned as an embedded file).', self::obj(['id' => self::int('Invoice id')], ['id']),
            fn(array $a) => ToolResult::text("Invoice {$a['id']} PDF")->file("fiscozen://invoices/{$a['id']}.pdf", 'application/pdf', $this->authed()->invoicePdf((int)$a['id'])), self::RO);
        $s->tool('get_invoice_xml', 'Download the FatturaPA XML of an invoice already sent to SDI.', self::obj(['id' => self::int('Invoice id')], ['id']),
            fn(array $a) => $this->authed()->invoiceXml((int)$a['id']), self::RO);
        $s->tool('list_credit_notes', 'List credit notes.', self::obj(['page' => self::int('Page, default 1')]),
            fn(array $a) => $this->compactPage($this->authed()->listCreditNotes((int)($a['page'] ?? 1))), self::RO);
        $s->tool('get_credit_note_pdf', 'Download a credit note PDF.', self::obj(['id' => self::int('Credit note id')], ['id']),
            fn(array $a) => ToolResult::text("Credit note {$a['id']} PDF")->file("fiscozen://credit-notes/{$a['id']}.pdf", 'application/pdf', $this->authed()->creditNotePdf((int)$a['id'])), self::RO);
        $s->tool('list_payment_methods', 'Collection methods usable on invoices (id, method, IBAN).', self::obj([]),
            fn() => array_map(fn($m) => array_intersect_key($m, array_flip(['id', 'method', 'details', 'is_active'])), $this->authed()->invoicePaymentMethods()), self::RO);
        $s->tool('list_vat_codes', 'VAT / nature codes allowed for the account fiscal regime.', self::obj(['date' => self::str('YYYY-MM-DD, default today')]),
            fn(array $a) => array_map(fn($v) => array_intersect_key($v, array_flip(['code', 'kind', 'value', 'description'])), $this->authed()->vatCodes($a['date'] ?? null)), self::RO);
        $s->tool('get_invoice_numbering', 'Next invoice number per year.', self::obj([]),
            fn() => $this->authed()->invoiceNumbering(), self::RO);

        // customers (read)
        $s->tool('list_customers', 'List customers (name, VAT/tax code, counterpart_version, totals).', self::obj(['search' => self::str('Filter by name')]),
            fn(array $a) => array_map([$this, 'compactCounterpart'], iterator_to_array($this->authed()->allCustomers($a['search'] ?? ''), false)), self::RO);
        $s->tool('list_providers', 'List suppliers.', self::obj(['search' => self::str('Filter by name')]),
            fn(array $a) => array_map([$this, 'compactCounterpart'], iterator_to_array($this->authed()->allProviders($a['search'] ?? ''), false)), self::RO);
        $s->tool('get_customer', 'Customer detail by id (the "counterpart" field).', self::obj(['id' => self::int('Customer id')], ['id']),
            fn(array $a) => $this->authed()->counterpart((int)$a['id']), self::RO);
        $s->tool('find_customer', 'Find a customer by exact VAT number or tax code. Returns null if missing.',
            self::obj(['tax_id' => self::str('VAT number or codice fiscale')], ['tax_id']),
            fn(array $a) => $this->authed()->findCustomer((string)$a['tax_id']), self::RO);

        // expenses, taxes, documents
        $s->tool('list_expenses', 'Received invoices / expenses.', self::obj([
            'date_from' => self::str('YYYY-MM-DD'), 'date_to' => self::str('YYYY-MM-DD'),
            'search' => self::str('Search supplier/subject'), 'page' => self::int('Page, default 1'),
        ]), fn(array $a) => $this->compactPage($this->authed()->listExpenses((int)($a['page'] ?? 1), 50, $a['date_from'] ?? null, $a['date_to'] ?? null, $a['search'] ?? '')), self::RO);
        $s->tool('list_tax_payments', 'F24 tax and contribution deadlines.', self::obj(['include_past' => self::bool('Include past deadlines')]),
            fn(array $a) => array_map(fn($p) => array_intersect_key($p, array_flip(['id', 'type', 'amount', 'paid_amount', 'due_date', 'description', 'expired', 'done_by_user'])),
                $this->authed()->taxPayments((bool)($a['include_past'] ?? false))), self::RO);
        $s->tool('get_tax_forecasts', 'Forecast of upcoming taxes and contributions.', self::obj([]),
            fn() => $this->authed()->taxForecasts(), self::RO);
        $s->tool('list_documents', 'Documents archive (tax returns, F24 receipts, contracts...).', self::obj([]),
            fn() => array_map(fn($d) => array_intersect_key($d, array_flip(['id', 'description', 'file_name_ext', 'tag', 'upload_date', 'expiration_date'])),
                iterator_to_array($this->authed()->allDocuments(), false)), self::RO);
        $s->tool('get_document', 'Download a document from the archive.', self::obj(['id' => self::int('Document id')], ['id']),
            function (array $a) {
                $fz = $this->authed();
                foreach ($fz->allDocuments() as $d) {
                    if ((int)$d['id'] === (int)$a['id']) {
                        $ext = strtolower(pathinfo($d['file_name_ext'], PATHINFO_EXTENSION));
                        $mime = ['pdf' => 'application/pdf', 'xml' => 'application/xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext] ?? 'application/octet-stream';
                        return ToolResult::text($d['file_name_ext'])->file("fiscozen://documents/{$d['id']}/{$d['file_name_ext']}", $mime, $fz->downloadDocument($d));
                    }
                }
                throw new \RuntimeException("Document {$a['id']} not found");
            }, self::RO);

        // SdI (Sistema di Interscambio)
        $s->tool('get_sdi_status', 'SdI status of an issued invoice with its meaning: to send, pending, delivered, made available (not delivered), rejected (scartata), accepted/refused by a PA, plus SdI errors.',
            self::obj(['id' => self::int('Invoice id')], ['id']),
            fn(array $a) => $this->authed()->sdiStatus((int)$a['id']), self::RO);
        $s->tool('list_sdi_problems', 'Issued invoices that need action on the SdI side: rejected (resend within 5 days), not delivered (tell the customer), refused by a PA, or still to send.',
            self::obj([]), fn() => $this->authed()->sdiProblems(), self::RO);
        $s->tool('get_sdi_summary', 'Number of issued invoices per SdI state.', self::obj([]),
            fn() => $this->authed()->sdiSummary(), self::RO);
        $s->tool('check_customer_for_sdi', 'Check a customer against SdI delivery rules before invoicing: recipient code (7 chars, 0000000 consumers, XXXXXXX foreign), PEC, PA CUU, VAT number and tax code checksums, address.',
            self::obj(['id' => self::int('Customer id (counterpart)')], ['id']),
            fn(array $a) => $this->authed()->checkCustomerForSdi((int)$a['id']), self::RO);
        $s->tool('check_invoice', 'Pre-flight check of an invoice before creating or sending it: customer SdI data, 12-day emission deadline from the operation date, stamp duty. Nothing is created.',
            self::obj(self::invoiceProps() + ['operation_date' => self::str('Date of the operation/service (YYYY-MM-DD), to check the 12-day emission deadline')], ['rows']),
            fn(array $a) => $this->authed()->checkInvoice($this->payload($a), $a['operation_date'] ?? null), self::RO);
        $s->tool('validate_tax_id', 'Validate an Italian VAT number (partita IVA, 11 digits) or tax code (codice fiscale) checksum offline. Wrong numbers are a common cause of SdI rejection.',
            self::obj(['tax_id' => self::str('VAT number or codice fiscale')], ['tax_id']),
            function (array $a) {
                $v = strtoupper(preg_replace('/\s+/', '', (string)$a['tax_id']));
                $isVat = (bool)preg_match('/^(IT)?\d{11}$/', $v);
                return ['tax_id' => $v, 'kind' => $isVat ? 'partita_iva' : 'codice_fiscale',
                    'valid' => $isVat ? \FiscozenApi\Sdi::isValidVat($v) : \FiscozenApi\Sdi::isValidTaxCode($v)];
            }, ['readOnlyHint' => true, 'openWorldHint' => false]);
        $s->tool('list_received_einvoices', 'E-invoices received from SdI (supplier invoices addressed to you).', self::obj([]),
            fn() => iterator_to_array($this->authed()->allReceivedEInvoices(), false), self::RO);

        // ChatGPT deep-research / connector compatibility: search + fetch
        $s->tool('search', 'Search Fiscozen invoices, customers and documents. Returns ids usable with fetch.',
            self::obj(['query' => self::str('Free text: customer name, invoice number, VAT, document name')], ['query']),
            fn(array $a) => ['results' => $this->search((string)$a['query'])], self::RO);
        $s->tool('fetch', 'Fetch a search result by id (invoice:<id>, customer:<id>, document:<id>).',
            self::obj(['id' => self::str('Result id from search')], ['id']),
            fn(array $a) => $this->fetch((string)$a['id']), self::RO);

        if ($readonly) {
            return;
        }

        // customers (write)
        $s->tool('create_customer', 'Create a customer. Italian companies need vat, Italian private persons need ssn. Returns counterpart (id) and counterpart_version (for invoices).',
            self::obj(self::customerProps(), ['typology', 'address', 'municipality', 'postcode']),
            fn(array $a) => $this->authed()->createCustomer($a), self::WRITE);
        $s->tool('update_customer', 'Update customer fields. Creates a new counterpart_version; past invoices keep the old one.',
            self::obj(['id' => self::int('Customer id')] + self::customerProps(), ['id']),
            fn(array $a) => $this->authed()->updateCounterpart((int)$a['id'], array_diff_key($a, ['id' => 1])), self::WRITE);
        $s->tool('delete_customer', 'Delete a customer (only possible if it has no invoices).', self::obj(['id' => self::int('Customer id')], ['id']),
            function (array $a) { $this->authed()->deleteCounterpart((int)$a['id']); return ['deleted' => (int)$a['id']]; }, self::DESTRUCTIVE);

        // invoices (write)
        $s->tool('preview_invoice', 'Compute an invoice without creating it: totals, next number, VAT codes, mandatory notes, stamp duty. Use before create_invoice.',
            self::obj(self::invoiceProps(), ['rows']), function (array $a) {
                $r = $this->authed()->computeInvoice($this->payload($a));
                return array_intersect_key($r, array_flip(['full_id', 'invoice_date', 'total_amount', 'total_amount_eur', 'currency_code', 'rows',
                    'all_notes', 'tax_stamp_applicable', 'add_tax_stamp_row', 'withholding_tax_applicable', 'welfare_applicable', 'fiscal_regime']));
            }, self::RO);
        $s->tool('create_invoice', 'Create (and number) an invoice. It is NOT sent to SDI: status stays DA_INVIARE/TO_SEND until send_invoice_to_sdi.',
            self::obj(self::invoiceProps(), ['rows']),
            fn(array $a) => array_intersect_key($this->authed()->createInvoice($this->payload($a)),
                array_flip(['id', 'full_id', 'invoice_date', 'amount', 'status', 'e_status', 'counterpart', 'counterpart_version'])), self::WRITE);
        $s->tool('update_invoice', 'Replace lines/fields of an invoice not yet sent to SDI.',
            self::obj(['id' => self::int('Invoice id')] + self::invoiceProps(), ['id', 'rows']),
            fn(array $a) => array_intersect_key($this->authed()->updateInvoice((int)$a['id'], $this->payload($a)),
                array_flip(['id', 'full_id', 'invoice_date', 'amount', 'status', 'e_status'])), self::WRITE);
        $s->tool('delete_invoice', 'Delete an invoice not yet sent to SDI (frees its number if it is the last one).',
            self::obj(['id' => self::int('Invoice id')], ['id']),
            function (array $a) { $this->authed()->deleteInvoice((int)$a['id']); return ['deleted' => (int)$a['id']]; }, self::DESTRUCTIVE);
        $s->tool('mark_invoice_paid', 'Mark an invoice as collected.', self::obj([
            'id' => self::int('Invoice id'), 'payment_date' => self::str('YYYY-MM-DD, default today'),
            'payment_method_id' => self::int('Collection method id'),
        ], ['id']), fn(array $a) => array_intersect_key($this->authed()->markInvoicePaid((int)$a['id'], $a['payment_date'] ?? null,
            isset($a['payment_method_id']) ? (int)$a['payment_method_id'] : null), array_flip(['id', 'full_id', 'status', 'payment_date'])), self::WRITE);

        if (getenv('FISCOZEN_MCP_ALLOW_SDI') === '1') {
            $s->tool('send_invoice_to_sdi', 'IRREVERSIBLE: send the electronic invoice to the Italian SDI. Once accepted it can only be corrected with a credit note. Only call after the user explicitly confirmed this specific invoice; pass confirm=true.',
                self::obj(['id' => self::int('Invoice id'), 'confirm' => self::bool('Must be true: the user explicitly confirmed sending')], ['id', 'confirm']),
                function (array $a) {
                    if (($a['confirm'] ?? false) !== true) {
                        throw new \RuntimeException('Not sent: confirm must be true after explicit user confirmation.');
                    }
                    $r = $this->authed()->sendInvoiceToSdi((int)$a['id']);
                    return array_intersect_key($r, array_flip(['id', 'full_id', 'status', 'e_status', 'e_invoice_sent_date', 'e_errors']));
                }, self::DESTRUCTIVE + ['idempotentHint' => false]);
        }
    }

    // ----------------------------------------------------------- helpers

    private function payload(array $a): array
    {
        $fz = $this->authed();
        $cv = $a['counterpart_version'] ?? null;
        if (!$cv) {
            if (empty($a['customer_tax_id'])) {
                throw new \InvalidArgumentException('Pass counterpart_version or customer_tax_id');
            }
            $c = $fz->findCustomer((string)$a['customer_tax_id'])
                ?? throw new \RuntimeException("Customer {$a['customer_tax_id']} not found: create it with create_customer");
            $cv = $c['counterpart_version'];
        }
        $method = $a['payment_method_id'] ?? null;
        if (!$method) {
            foreach ($fz->invoicePaymentMethods() as $m) {
                if ($m['method'] === 'Bonifico' && $m['is_active']) {
                    $method = $m['id'];
                    break;
                }
            }
        }
        return $fz->buildInvoice((int)$cv, $a['rows'], array_filter([
            'invoice_date' => $a['invoice_date'] ?? null,
            'payment_method' => $method ? (int)$method : null,
            'payment_due_date' => $a['payment_due_date'] ?? null,
            'custom_notes' => $a['notes'] ?? null,
            'is_e_invoice' => $a['is_e_invoice'] ?? null,
            'e_doc_data' => $a['e_doc_data'] ?? null,
        ], fn($v) => $v !== null));
    }

    private function compactPage(array $page): array
    {
        return [
            'count' => $page['count'] ?? null,
            'has_next' => !empty($page['next']),
            'results' => array_map(fn($r) => array_diff_key($r, ['data' => 1]), $page['results'] ?? []),
        ];
    }

    private function compactCounterpart(array $c): array
    {
        return array_intersect_key($c, array_flip([
            'counterpart', 'counterpart_version', 'display_name', 'typology', 'vat', 'ssn', 'email', 'pec',
            'recipient_code', 'municipality', 'country', 'total_invoice_amount', 'latest_invoice_date',
        ]));
    }

    private function search(string $q): array
    {
        $fz = $this->authed();
        $q = mb_strtolower(trim($q));
        $hit = fn(...$fields) => $q === '' || str_contains(mb_strtolower(implode(' ', array_map('strval', $fields))), $q);
        $out = [];
        foreach ($fz->allInvoices() as $i) {
            if ($hit($i['full_id'], $i['counterpart_version_display_name'], $i['invoice_date'], $i['amount'])) {
                $out[] = ['id' => "invoice:{$i['id']}", 'title' => "Fattura {$i['full_id']} - {$i['counterpart_version_display_name']} - {$i['amount']} EUR",
                    'url' => Fiscozen::BASE . "/app/fatture/{$i['id']}"];
            }
        }
        foreach ($fz->allCustomers() as $c) {
            if ($hit($c['display_name'], $c['vat'], $c['ssn'], $c['email'])) {
                $out[] = ['id' => "customer:{$c['counterpart']}", 'title' => "Cliente {$c['display_name']}", 'url' => Fiscozen::BASE . "/app/clienti/{$c['counterpart']}"];
            }
        }
        foreach ($fz->allDocuments() as $d) {
            if ($hit($d['file_name_ext'], $d['description'], $d['tag'])) {
                $out[] = ['id' => "document:{$d['id']}", 'title' => "Documento {$d['file_name_ext']}", 'url' => Fiscozen::BASE . '/app/documenti'];
            }
        }
        return array_slice($out, 0, 50);
    }

    private function fetch(string $id): array
    {
        [$kind, $num] = array_pad(explode(':', $id, 2), 2, '');
        $fz = $this->authed();
        $data = match ($kind) {
            'invoice' => $fz->invoice((int)$num),
            'customer' => $fz->counterpart((int)$num),
            'document' => (function () use ($fz, $num) {
                foreach ($fz->allDocuments() as $d) {
                    if ((int)$d['id'] === (int)$num) {
                        return $d;
                    }
                }
                throw new \RuntimeException("Document $num not found");
            })(),
            default => throw new \InvalidArgumentException("Unknown id: $id"),
        };
        return ['id' => $id, 'title' => $data['full_id'] ?? $data['display_name'] ?? $data['file_name_ext'] ?? $id,
            'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'url' => Fiscozen::BASE . '/app/', 'metadata' => ['kind' => $kind]];
    }
}
