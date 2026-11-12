<?php

declare(strict_types=1);

namespace FiscozenApi;

/**
 * Typed-ish facade over the Fiscozen REST API (``/api/v1``), reverse engineered from app.fiscozen.it.
 *
 * Lists are DRF paginated: methods named ``list*`` return one page ({count, next, previous, results}),
 * methods named ``all*`` return a Generator over every item.
 */
class Fiscozen extends FiscozenClient
{
    public const SECTION_ARCHIVE = 'ARCHIVIO';
    public const SECTION_TO_COLLECT = 'DA_INCASSARE';
    public const SECTION_TO_SEND = 'DA_INVIARE';

    private const INVOICE_ORDERING = '-invoice_date,-invoice_number,-id';

    private ?array $self = null;

    // -------------------------------------------------------------- account

    /** Current user profile (anagrafica, P.IVA, regime fiscale, ATECO...). */
    public function me(bool $refresh = false): array
    {
        if ($this->self === null || $refresh) {
            $this->self = $this->get('/api/v1/users/self/');
        }
        return $this->self;
    }

    public function userId(): int
    {
        return (int)$this->me()['id'];
    }

    public function subscriptions(): array
    {
        return $this->get('/api/v1/subscriptions/');
    }

    public function extraServices(): array
    {
        return $this->get('/api/v1/extra_services/');
    }

    public function featureFlags(): array
    {
        return array_column($this->get('/api/v1/user_feature_flags/'), 'name');
    }

    public function addresses(string $kind = 'RESIDENCE'): array
    {
        return $this->get('/api/v1/user_addresses/', ['kind' => $kind]);
    }

    public function fiscalRegimes(): array
    {
        return $this->get('/api/v1/fiscal_regimes/');
    }

    /** Fatturato / tasse stimate dell'anno in tempo reale. */
    public function yearStats(?int $year = null): array
    {
        return $this->get('/api/v1/fiscal_profile/realtime_year_stats/', ['fiscal_year' => $year ?? (int)date('Y')]);
    }

    // ------------------------------------------------------------ invoices

    /** Fatture emesse. ``$section``: ARCHIVIO | DA_INCASSARE | DA_INVIARE. */
    public function listInvoices(string $section = self::SECTION_ARCHIVE, int $page = 1, int $pageSize = 50,
                                 bool $selfInvoice = false): array
    {
        return $this->get('/api/v1/invoices/', [
            'self_invoice' => $selfInvoice ? 'true' : 'false', 'user' => $this->userId(),
            'section' => $section, 'page' => $page, 'page_size' => $pageSize,
            'ordering' => self::INVOICE_ORDERING,
        ]);
    }

    public function allInvoices(string $section = self::SECTION_ARCHIVE, bool $selfInvoice = false): \Generator
    {
        return $this->paginate('/api/v1/invoices/', [
            'self_invoice' => $selfInvoice ? 'true' : 'false', 'user' => $this->userId(),
            'section' => $section, 'page_size' => 100, 'ordering' => self::INVOICE_ORDERING,
        ]);
    }

    public function invoice(int $id): array
    {
        return $this->get("/api/v1/invoices/$id/");
    }

    public function invoicePdf(int $id): string
    {
        return $this->download("/esporta/fatture/$id/", ['pdf' => '']);
    }

    public function invoiceXml(int $id): string
    {
        return $this->download("/esporta/fatture/$id/", ['xml' => '']);
    }

    /** Prossimo numero fattura per anno. */
    public function invoiceNumbering(): array
    {
        return $this->get('/api/v1/invoices/', ['config' => 'true']);
    }

    public function invoicingSettings(): array
    {
        return $this->get('/api/v1/invoicing-settings/');
    }

    /** Metodi di incasso configurati (IBAN ecc.). */
    public function invoicePaymentMethods(): array
    {
        return $this->get('/api/v1/invoicing/payment_methods/', ['status' => 'active']);
    }

    // --------------------------------------------------------- credit notes

    public function listCreditNotes(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v1/credit_notes/', [
            'user' => $this->userId(), 'page' => $page, 'page_size' => $pageSize,
            'ordering' => '-credit_note_date,-credit_note_number,-id',
        ]);
    }

    public function allCreditNotes(): \Generator
    {
        return $this->paginate('/api/v1/credit_notes/', [
            'user' => $this->userId(), 'page_size' => 100, 'ordering' => '-credit_note_date,-credit_note_number,-id',
        ]);
    }

    public function creditNote(int $id): array
    {
        return $this->get("/api/v1/credit_notes/$id/");
    }

    public function creditNotePdf(int $id): string
    {
        return $this->download("/esporta/note_di_credito/$id/", ['pdf' => '']);
    }

    public function creditNoteXml(int $id): string
    {
        return $this->download("/esporta/note_di_credito/$id/", ['xml' => '']);
    }

    // -------------------------------------------- receipts (prestazioni occasionali)

    public function allReceipts(): \Generator
    {
        return $this->paginate('/api/v1/receipts/', ['user' => $this->userId(), 'page_size' => 100, 'ordering' => '-id']);
    }

    public function receiptPdf(int $id): string
    {
        return $this->download("/esporta/ricevute/$id/", ['pdf' => '']);
    }

    // ------------------------------------------- expenses (fatture ricevute / spese)

    /** Fatture passive / spese. Date in formato Y-m-d. */
    public function listExpenses(int $page = 1, int $pageSize = 50, ?string $from = null, ?string $to = null,
                                 string $search = ''): array
    {
        return $this->get('/api/v1/passive_invoices/', [
            'user' => $this->userId(), 'date_from' => $from ?? '', 'date_to' => $to ?? '',
            'irpef_deductible' => '', 'search_subject' => $search,
            'page' => $page, 'page_size' => $pageSize, 'ordering' => '-invoice_date',
        ]);
    }

    public function allExpenses(?string $from = null, ?string $to = null): \Generator
    {
        return $this->paginate('/api/v1/passive_invoices/', [
            'user' => $this->userId(), 'date_from' => $from ?? '', 'date_to' => $to ?? '',
            'page_size' => 100, 'ordering' => '-invoice_date',
        ]);
    }

    public function expense(int $id): array
    {
        return $this->get("/api/v1/passive_invoices/$id/");
    }

    // ------------------------------------------- counterparts (clienti/fornitori)

    public function allCustomers(string $search = ''): \Generator
    {
        return $this->counterparts('CUSTOMER', $search);
    }

    public function allProviders(string $search = ''): \Generator
    {
        return $this->counterparts('PROVIDER', $search);
    }

    private function counterparts(string $type, string $search): \Generator
    {
        return $this->paginate('/api/v1/counterparts/', [
            'counterpart_type' => $type, 'typology__in' => '', 'user' => $this->userId(),
            'search' => $search, 'ordering' => 'display_name', 'page_size' => 100,
        ]);
    }

    /** Cliente/fornitore per id (contiene ``counterpart_version`` da usare in fattura). */
    public function counterpart(int $id): array
    {
        return $this->get("/api/v1/counterparts/$id/");
    }

    /** Risolve un ``counterpart_version`` (come salvato in fattura) nel cliente corrispondente. */
    public function counterpartByVersion(int $versionId): ?array
    {
        $page = $this->get('/api/v1/counterparts/', ['version__in' => $versionId]);
        $rows = $page['results'] ?? (array_is_list((array)$page) ? $page : []);
        return $rows[0] ?? null;
    }

    /** Cerca un cliente per partita IVA o codice fiscale (match esatto). */
    public function findCustomer(string $vatOrSsn): ?array
    {
        // the server-side ``search`` filter matches names only: compare tax ids client side
        $needle = strtoupper(preg_replace('/\s+/', '', $vatOrSsn));
        foreach ($this->allCustomers() as $row) {
            if (strtoupper((string)($row['vat'] ?? '')) === $needle || strtoupper((string)($row['ssn'] ?? '')) === $needle) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Crea un cliente.
     *
     * Campi obbligatori: ``typology`` (PRIV privato | PROF professionista | SOCI società | PA | NOPR no-profit),
     * ``address``, ``municipality``, ``postcode``, ``country`` (ISO2, es. IT).
     * Poi ``business_name`` (aziende) oppure ``first_name``/``last_name`` (persone), ``vat`` e/o ``ssn``,
     * e per la fattura elettronica ``recipient_code`` (codice SDI, 7 caratteri) o ``pec``.
     * Facoltativi: province, email, phone, website, contact, fax.
     */
    public function createCustomer(array $data): array
    {
        return $this->post('/api/v1/counterparts/', $data + ['counterpart_type' => 'CUSTOMER', 'country' => 'IT']);
    }

    public function createProvider(array $data): array
    {
        return $this->post('/api/v1/counterparts/', $data + ['counterpart_type' => 'PROVIDER', 'country' => 'IT']);
    }

    /** Modifica parziale. Fiscozen versiona l'anagrafica: le fatture già emesse restano sulla versione vecchia. */
    public function updateCounterpart(int $id, array $data): array
    {
        return $this->patch("/api/v1/counterparts/$id/", $data);
    }

    public function deleteCounterpart(int $id): void
    {
        $this->delete("/api/v1/counterparts/$id/");
    }

    /** Valori ammessi per i campi choice dei clienti (typology, country...). */
    public function counterpartChoices(string $field = 'typology'): array
    {
        $o = $this->request('OPTIONS', '/api/v1/counterparts/');
        return array_column($o['actions']['POST'][$field]['choices'] ?? [], 'display_name', 'value');
    }

    // ------------------------------------------------------ invoice writing

    /**
     * Costruisce il payload di una fattura come lo invia la web app.
     *
     * @param int   $counterpartVersion ``counterpart_version`` del cliente (da counterpart()/createCustomer())
     * @param array $rows  righe: [['description' => '...', 'amount' => 100.0, 'quantity' => 1], ...]
     *                     ``vat_code`` facoltativo, codice stringa (es. 'NI3', 'VAT22', 'RC02') o oggetto:
     *                     se omesso usa ``$opts['vat_code']`` o il default del regime (forfettario NI3,
     *                     minimi NS27, ordinario VAT22). Elenco: vatCodes().
     * @param array $opts  invoice_date (Y-m-d, default oggi), payment_method (id da invoicePaymentMethods()),
     *                     payment_due_date, reason (id da reasons(), default FATTURA EMESSA),
     *                     custom_notes (string[]), is_e_invoice (default true), vat_ateco (default ATECO primario),
     *                     currency_code (default EUR), e_doc_data (cig_code, cup_code, ...), payment_date
     */
    public function buildInvoice(int $counterpartVersion, array $rows, array $opts = []): array
    {
        $me = $this->me();
        $date = $opts['invoice_date'] ?? date('Y-m-d');
        $defaultVat = $opts['vat_code'] ?? $this->defaultVatCode();
        $rows = array_values(array_map(function (array $r, int $i) use ($date, $defaultVat) {
            $vat = $r['vat_code'] ?? $defaultVat;
            $r['vat_code'] = is_array($vat) ? $vat : $this->vatCode((string)$vat, $date);
            $qty = (float)($r['quantity'] ?? 1);
            $amount = (float)$r['amount'];
            return $r + [
                'key' => 'row' . ($i + 1),
                'quantity' => (string)$qty,
                'amount' => number_format($amount, 2, '.', ''),
                'total' => number_format(round($qty * $amount, 3), 2, '.', ''),
                'welfare_applied' => false,
                'enasarco_applied' => false,
                'ex_enpals_applied' => false,
                'bonus_psychologist' => false,
            ];
        }, $rows, array_keys($rows)));
        $total = array_sum(array_map(fn($r) => (float)$r['total'], $rows));

        $common = [
            'invoice_date' => $date,
            'is_e_invoice' => $opts['is_e_invoice'] ?? true,
            'is_da_invoice' => false,
            'da_kind' => '',
            'custom_full_id' => '',
            'reason' => $opts['reason'] ?? null,
            'reason_modified_by_bo_at' => null,
            'tax_stamp' => $opts['tax_stamp'] ?? '',
            'vat_ateco' => $opts['vat_ateco'] ?? ($me['vat_ateco_1'] ?? ''),
        ];

        return $common + [
            'self_invoice' => false,
            'amount' => number_format($total, 2, '.', ''),
            'counterpart_version' => $counterpartVersion,
            'user_logo' => null,
            'payment_method' => $opts['payment_method'] ?? null,
            'attachments' => [],
            'template_version' => '2',
            'payment_date' => $opts['payment_date'] ?? null,
            '_data_params' => $common + [
                'use_last_saved' => false,
                'invoice_id' => null,
                'counterpart_version_id' => $counterpartVersion,
                'add_ex_enpals_contribution' => false,
                'add_tax_stamp_row' => $opts['add_tax_stamp_row'] ?? false,
                'add_withholding_tax' => $opts['add_withholding_tax'] ?? false,
                'currency_code' => $opts['currency_code'] ?? 'EUR',
                'custom_notes' => array_values((array)($opts['custom_notes'] ?? [])),
                'e_doc_data' => (object)($opts['e_doc_data'] ?? []),
                'enasarco_invoice_kind' => '',
                'ex_enpals' => ['integrativo' => '', 'solidarieta' => ''],
                'no_sts_permission' => false,
                'payment_due_date' => $opts['payment_due_date'] ?? null,
                'payment_method_id' => $opts['payment_method'] ?? null,
                'reimbursements' => [],
                'analytic_refunds' => [],
                'rows' => $rows,
                'service_kind' => '',
                'sts_invoice_type' => '',
                'sts_applicable' => false,
                'user_logo_id' => null,
                // NB: non inviare update_*_from_db su fatture nuove: il server risponde 500
            ],
        ];
    }

    /**
     * Anteprima calcolata dal server (totali, IVA/natura, bollo, contributi, note obbligatorie).
     * Non crea nulla. Le ``rows`` restituite sono complete di ``vat_code``.
     */
    public function computeInvoice(array $payload): array
    {
        return $this->post('/api/v1/invoices/compute_data/', $payload['_data_params']);
    }

    /**
     * Crea la fattura in Fiscozen e le assegna il numero progressivo.
     * NON la invia allo SDI: per quello serve sendInvoiceToSdi().
     */
    public function createInvoice(array $payload): array
    {
        return $this->post('/api/v1/invoices/', $payload);
    }

    /** Modifica una fattura non ancora inviata (stesso formato di buildInvoice()). */
    public function updateInvoice(int $id, array $payload): array
    {
        $payload['_data_params']['invoice_id'] = $id;
        return $this->patch("/api/v1/invoices/$id/", $payload);
    }

    /** Elimina una fattura non ancora inviata allo SDI. */
    public function deleteInvoice(int $id): void
    {
        $this->delete("/api/v1/invoices/$id/");
    }

    /**
     * Anteprima dell'XML FatturaPA che verrebbe inviato allo SDI (fattura non ancora inviata).
     * ``$eDocData``: dati FatturaPA extra (cig_code, cup_code, pec, recipient_code...).
     * Per le fatture già inviate usare invoiceXml().
     */
    public function invoicePreviewXml(int $id, array $eDocData = []): string
    {
        return $this->download("/api/v1/invoices/$id/preview_xml/", [
            'e_doc_data' => json_encode((object)$eDocData),
        ]);
    }

    /**
     * Invia la fattura elettronica allo SDI. IRREVERSIBILE: una volta accettata dallo SDI
     * si corregge solo con una nota di credito.
     */
    public function sendInvoiceToSdi(int $id, string $sendType = 'sdi', bool $customizeXml = false): array
    {
        return $this->post("/api/v1/invoices/$id/enqueue_invoice/", [
            'e_doc_send_type' => $sendType, 'customize_xml' => $customizeXml,
        ]);
    }

    /** Segna come incassata (data Y-m-d, default oggi). */
    public function markInvoicePaid(int $id, ?string $date = null, ?int $paymentMethod = null): array
    {
        return $this->patch("/api/v1/invoices/$id/mark_as_paid/", array_filter([
            'payment_date' => $date ?? date('Y-m-d'), 'payment_method' => $paymentMethod,
        ]));
    }

    /** Causali documento (TD01 fattura, TD06 parcella, ...). */
    public function reasons(): array
    {
        return $this->get('/api/v1/reasons/');
    }

    /** Formato richiesto (elettronica/cartacea) per una data. */
    public function invoiceFormatInfo(?string $date = null): array
    {
        return $this->get('/api/v1/invoices/invoice_format_info/', [
            'day' => $date ?? date('Y-m-d'), 'document_is_sts' => 'false',
        ]);
    }

    /** Codici IVA/natura ammessi per il proprio regime fiscale alla data. */
    public function vatCodes(?string $date = null): array
    {
        $l = $this->get('/api/v1/vat_codes/', ['day' => $date ?? date('Y-m-d'), 'fiscal_regime' => $this->me()['fiscal_regime']]);
        return $l['results'] ?? $l;
    }

    private array $vatCache = [];

    public function vatCode(string $code, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        return $this->vatCache["$code@$date"] ??= $this->get("/api/v1/vat_codes/$code/", [
            'day' => $date, 'fiscal_regime' => $this->me()['fiscal_regime'],
        ]);
    }

    public function defaultVatCode(): string
    {
        $regime = strtolower((string)$this->me()['fiscal_regime']);
        return match (true) {
            str_contains($regime, 'forfett') => 'NI3',
            str_contains($regime, 'minimi') => 'NS27',
            default => 'VAT22',
        };
    }

    // ---------------------------------------------------------------- taxes

    /** Scadenze F24 (``$past`` = true include quelle passate). */
    public function taxPayments(bool $past = false): array
    {
        return $this->get('/api/v1/tax_payments/all_payments/', ['past_duties' => $past ? 'true' : 'false']);
    }

    /** Previsione tasse/contributi futuri. */
    public function taxForecasts(): array
    {
        return $this->get('/api/v1/tax_payments/forecasts/');
    }

    public function taxDeclarations(?int $refYear = null): mixed
    {
        return $this->get('/api/v1/user_tax_declarations/', $refYear ? ['ref_year' => $refYear] : []);
    }

    // ------------------------------------------------------------ documents

    public function allDocuments(): \Generator
    {
        return $this->paginate('/api/v1/documents/', ['ordering' => '-uploaded_file__upload_date', 'page_size' => 100]);
    }

    /** Download a document returned by allDocuments() (uses its ``doc`` URL). */
    public function downloadDocument(array $document): string
    {
        $url = $document['signed_url'] ?: $document['doc'];
        return $this->download(preg_replace('#^https?://[^/]+#', '', $url));
    }

    // -------------------------------------------------------- notifications

    // ------------------------------------------------------------------ SdI

    /** SdI status of an invoice, normalised with an Italian description (see Sdi::STATUSES). */
    public function sdiStatus(int $invoiceId): array
    {
        $inv = $this->invoice($invoiceId);
        return ['id' => $inv['id'], 'full_id' => $inv['full_id']] + Sdi::status($inv);
    }

    /**
     * Issued invoices that need attention on the SdI side: rejected (scartate), not delivered
     * (messe a disposizione), refused by a PA, or still to send. Each item has the normalised status.
     */
    public function sdiProblems(): array
    {
        $out = [];
        foreach ($this->allInvoices() as $i) {
            $s = Sdi::status($i);
            if ($s['needs_action']) {
                $out[] = ['id' => $i['id'], 'full_id' => $i['full_id'], 'invoice_date' => $i['invoice_date'],
                    'customer' => $i['counterpart_version_display_name'] ?? null, 'amount' => $i['amount']] + $s
                    + ($s['state'] === 'rejected' && $s['changed_at'] ? ['resend_by' => Sdi::resendDeadline((string)$s['changed_at'])] : []);
            }
        }
        return $out;
    }

    /** Count of issued invoices per SdI state. */
    public function sdiSummary(): array
    {
        $count = [];
        foreach ($this->allInvoices() as $i) {
            $state = Sdi::status($i)['state'];
            $count[$state] = ($count[$state] ?? 0) + 1;
        }
        return $count;
    }

    /** Check a customer against SdI delivery rules (recipient code, PEC, CUU, VAT and tax code checksums). */
    public function checkCustomerForSdi(int $counterpartId): array
    {
        return Sdi::checkCustomer($this->counterpart($counterpartId));
    }

    /**
     * Pre-flight checks before creating/sending an invoice: customer data, emission deadline, stamp duty.
     * Purely advisory, nothing is sent. $payload is the output of buildInvoice().
     */
    public function checkInvoice(array $payload, ?string $operationDate = null): array
    {
        $customer = $this->counterpartByVersion((int)$payload['counterpart_version']);
        $res = $customer ? Sdi::checkCustomer($customer) : ['ok' => false, 'channel' => null, 'errors' => ['Cliente non trovato'], 'warnings' => []];
        $date = $payload['invoice_date'] ?? date('Y-m-d');
        if ($operationDate) {
            $deadline = Sdi::issueDeadline($operationDate);
            if ($date > $deadline) {
                $res['warnings'][] = "Emissione oltre i 12 giorni dall'operazione ($operationDate, termine $deadline): rischio sanzioni";
            }
        }
        if ($date > date('Y-m-d')) {
            $res['errors'][] = 'Data fattura nel futuro';
        }
        $rows = $payload['_data_params']['rows'] ?? [];
        if (!$rows) {
            $res['errors'][] = 'Nessuna riga in fattura';
        }
        $exempt = 0.0;
        foreach ($rows as $r) {
            if ((float)($r['vat_code']['value'] ?? 0) == 0.0) {
                $exempt += (float)$r['total'];
            }
            if (trim((string)($r['description'] ?? '')) === '') {
                $res['errors'][] = 'Riga senza descrizione';
            }
        }
        if (Sdi::stampDutyDue($exempt)) {
            $res['warnings'][] = 'Importo esente oltre 77,47 €: è dovuta la marca da bollo da 2 € (Fiscozen la gestisce in automatico)';
        }
        $res['ok'] = !$res['errors'];
        return $res;
    }

    /**
     * Export issued invoices (PDF + FatturaPA XML) and credit notes to a folder for archiving,
     * with a manifest.json carrying SHA-256 hashes. Legal "conservazione a norma" (10 years) still needs
     * an accredited service (e.g. the free one of the Agenzia delle Entrate): this is a backup copy.
     */
    public function archiveInvoices(string $dir, ?int $year = null): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new FiscozenException("Cannot create $dir");
        }
        $manifest = [];
        $save = function (string $name, string $bytes, array $meta) use ($dir, &$manifest) {
            file_put_contents("$dir/$name", $bytes);
            $manifest[] = $meta + ['file' => $name, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        };
        foreach ($this->allInvoices() as $i) {
            if ($year && (int)substr((string)$i['invoice_date'], 0, 4) !== $year) {
                continue;
            }
            $base = 'fattura_' . str_replace('/', '-', $i['full_id']) . '_' . $i['invoice_date'];
            $meta = ['type' => 'invoice', 'id' => $i['id'], 'full_id' => $i['full_id'], 'date' => $i['invoice_date'], 'e_status' => $i['e_status'] ?? null];
            $save("$base.pdf", $this->invoicePdf((int)$i['id']), $meta + ['format' => 'pdf']);
            if (!empty($i['is_e_invoice']) && !empty($i['can_download_last_sent_xml'])) {
                $save("$base.xml", $this->invoiceXml((int)$i['id']), $meta + ['format' => 'xml']);
            }
        }
        foreach ($this->allCreditNotes() as $n) {
            if ($year && (int)substr((string)$n['credit_note_date'], 0, 4) !== $year) {
                continue;
            }
            $base = 'nota_credito_' . str_replace('/', '-', $n['full_id']) . '_' . $n['credit_note_date'];
            $meta = ['type' => 'credit_note', 'id' => $n['id'], 'full_id' => $n['full_id'], 'date' => $n['credit_note_date']];
            $save("$base.pdf", $this->creditNotePdf((int)$n['id']), $meta + ['format' => 'pdf']);
            if (!empty($n['is_e_credit_note'])) {
                try {
                    $save("$base.xml", $this->creditNoteXml((int)$n['id']), $meta + ['format' => 'xml']);
                } catch (FiscozenException) {
                    // not every credit note has a sent XML
                }
            }
        }
        file_put_contents("$dir/manifest.json", json_encode(['exported_at' => date('c'), 'year' => $year, 'files' => $manifest],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['dir' => $dir, 'files' => count($manifest)];
    }

    /** E-invoices received from SdI (supplier invoices to you), as listed in the "fatture ricevute" inbox. */
    public function allReceivedEInvoices(): \Generator
    {
        return $this->paginate('/api/v1/sdi_received_documents/', ['page_size' => 100]);
    }

    /** SdI receipt / notification file of a received document. */
    public function receivedEInvoiceReceipt(int $receivedDocumentId): string
    {
        return $this->download("/esporta/fatture_elettroniche/$receivedDocumentId/", ['receipt' => '']);
    }

    public function allNotifications(): \Generator
    {
        return $this->paginate('/api/v1/notification_queue/', [
            'in_app' => 'true', 'user' => $this->userId(), 'ordering' => '-message_created', 'page_size' => 100,
        ]);
    }
}
