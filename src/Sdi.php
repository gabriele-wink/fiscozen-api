<?php

declare(strict_types=1);

namespace FiscozenApi;

/**
 * Local helpers for the Italian Sistema di Interscambio (SdI): status meaning, recipient-code rules,
 * VAT number / tax code checksums, deadlines. No network calls: use them before creating or sending
 * an invoice to catch the most common causes of rejection ("scarto").
 */
final class Sdi
{
    /** Fiscozen e_status => [state, Italian description, needs action]. Mapping taken from the web app. */
    public const STATUSES = [
        'DRAFT' => ['to_send', 'Bozza: non ancora inviata allo SdI', true],
        'TO_SEND' => ['to_send', 'Da inviare allo SdI', true],
        'SENDING' => ['pending', 'In invio allo SdI', false],
        'SENT' => ['pending', 'Inviata, in attesa di esito dallo SdI', false],
        'DELIVERED' => ['delivered', 'Consegnata al destinatario (ricevuta di consegna)', false],
        'NOT_DELIVERED' => ['made_available', 'Impossibilità di recapito: messa a disposizione nell\'area riservata del cliente. La fattura è valida, avvisa il cliente', true],
        'REJECTED' => ['rejected', 'Scartata dallo SdI: la fattura è come non emessa. Correggi e rinvia, idealmente entro 5 giorni', true],
        'ACCEPTED' => ['accepted', 'Accettata dalla Pubblica Amministrazione', false],
        'NOT_ACCEPTED' => ['refused', 'Rifiutata dalla Pubblica Amministrazione: verifica l\'esito e correggi', true],
        'UNKNOWN' => ['unknown', 'Stato sconosciuto', false],
    ];

    public const CODE_CONSUMER = '0000000';
    public const CODE_FOREIGN = 'XXXXXXX';

    /** Normalised SdI status for a Fiscozen invoice array. */
    public static function status(array $invoice): array
    {
        $e = $invoice['e_status'] ?? 'UNKNOWN';
        $isPa = ($invoice['counterpart_typology'] ?? null) === 'PA';
        [$state, $text, $action] = self::STATUSES[$e] ?? self::STATUSES['UNKNOWN'];
        if ($isPa && in_array($e, ['DELIVERED', 'NOT_DELIVERED'], true)) {
            [$state, $text, $action] = ['pa_waiting', 'Consegnata alla PA, in attesa di accettazione o rifiuto', false];
        }
        if (empty($invoice['is_e_invoice']) && !isset(self::STATUSES[$invoice['e_status'] ?? ''])) {
            [$state, $text, $action] = ['not_electronic', 'Fattura non elettronica: non passa dallo SdI', false];
        }
        return [
            'e_status' => $e,
            'state' => $state,
            'description' => $text,
            'needs_action' => $action,
            'errors' => $invoice['e_errors'] ?? [],
            'sent_at' => $invoice['sdi_document_sent_at'] ?? $invoice['e_invoice_sent_date'] ?? null,
            'changed_at' => $invoice['sdi_document_status_changed_at'] ?? $invoice['e_invoice_last_status_change_date'] ?? null,
        ];
    }

    /** Italian VAT number (partita IVA): 11 digits with Luhn-style check digit. */
    public static function isValidVat(string $vat): bool
    {
        $vat = preg_replace('/^IT/i', '', preg_replace('/\s+/', '', $vat));
        if (!preg_match('/^\d{11}$/', $vat) || $vat === '00000000000') {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $d = (int)$vat[$i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return (10 - $sum % 10) % 10 === (int)$vat[10];
    }

    /** Italian tax code (codice fiscale) of a person, 16 chars with check letter. Companies use the 11-digit VAT form. */
    public static function isValidTaxCode(string $cf): bool
    {
        $cf = strtoupper(preg_replace('/\s+/', '', $cf));
        if (preg_match('/^\d{11}$/', $cf)) {
            return self::isValidVat($cf);
        }
        if (!preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $cf)) {
            return false;
        }
        $odd = [0 => 1, 1 => 0, 2 => 5, 3 => 7, 4 => 9, 5 => 13, 6 => 15, 7 => 17, 8 => 19, 9 => 21, 10 => 2, 11 => 4, 12 => 18, 13 => 20,
            14 => 11, 15 => 3, 16 => 6, 17 => 8, 18 => 12, 19 => 14, 20 => 16, 21 => 10, 22 => 22, 23 => 25, 24 => 24, 25 => 23];
        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $c = $cf[$i];
            $v = ctype_digit($c) ? (int)$c : ord($c) - 65;
            $sum += $i % 2 === 0 ? $odd[$v] : $v;
        }
        return chr(65 + $sum % 26) === $cf[15];
    }

    /**
     * Check a customer record (as returned by counterpart()/allCustomers(), or the array passed to createCustomer())
     * against the SdI delivery rules. Returns ['ok' => bool, 'errors' => [...], 'warnings' => [...], 'channel' => ...].
     */
    public static function checkCustomer(array $c): array
    {
        $errors = $warnings = [];
        $country = strtoupper($c['country'] ?? 'IT');
        $typ = $c['typology'] ?? '';
        $code = strtoupper(trim((string)($c['recipient_code'] ?? '')));
        $pec = trim((string)($c['pec'] ?? ''));
        $vat = trim((string)($c['vat'] ?? ''));
        $ssn = trim((string)($c['ssn'] ?? ''));

        if ($country === 'IT') {
            if ($vat !== '' && !self::isValidVat($vat)) {
                $errors[] = "Partita IVA $vat non valida (cifra di controllo errata): causa frequente di scarto";
            }
            if ($ssn !== '' && !self::isValidTaxCode($ssn)) {
                $errors[] = "Codice fiscale $ssn non valido";
            }
            if (in_array($typ, ['SOCI', 'PROF'], true) && $vat === '') {
                $errors[] = 'Cliente con partita IVA: manca la partita IVA';
            }
            if ($typ === 'PRIV' && $ssn === '') {
                $errors[] = 'Consumatore privato: manca il codice fiscale';
            }
        } elseif ($code !== '' && $code !== self::CODE_FOREIGN) {
            $warnings[] = 'Cliente estero: il codice destinatario dovrebbe essere XXXXXXX';
        }
        if (trim((string)($c['address'] ?? '')) === '' || trim((string)($c['municipality'] ?? '')) === '' || trim((string)($c['postcode'] ?? '')) === '') {
            $errors[] = 'Indirizzo incompleto (via, comune e CAP sono obbligatori nel file FatturaPA)';
        }

        if ($typ === 'PA') {
            $channel = 'cuu';
            if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
                $errors[] = 'Pubblica Amministrazione: serve il CUU (codice univoco ufficio, 6 caratteri) da IndicePA';
            }
            $warnings[] = 'PA: verifica se servono CIG/CUP (e_doc_data cig_code, cup_code) e lo split payment';
        } elseif ($country !== 'IT') {
            $channel = 'foreign';
            if ($code === '') {
                $warnings[] = 'Cliente estero: usa codice destinatario XXXXXXX';
            }
        } elseif ($code !== '' && $code !== self::CODE_CONSUMER) {
            $channel = 'recipient_code';
            if (!preg_match('/^[A-Z0-9]{7}$/', $code)) {
                $errors[] = "Codice destinatario $code non valido: deve avere 7 caratteri alfanumerici";
            }
        } elseif ($pec !== '') {
            $channel = 'pec';
            if (!filter_var($pec, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "PEC $pec non valida";
            }
        } else {
            $channel = 'made_available';
            if (in_array($typ, ['SOCI', 'PROF', 'NOPR'], true)) {
                $warnings[] = 'Nessun codice destinatario né PEC: la fattura sarà messa a disposizione nell\'area riservata del cliente. Chiedi il codice o la PEC e avvisa il cliente';
            } else {
                $warnings[] = 'Consumatore: codice 0000000, la fattura è messa a disposizione nel portale dell\'Agenzia. Invia anche una copia di cortesia in PDF';
            }
        }
        return ['ok' => !$errors, 'channel' => $channel, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Issue deadline for an operation date (art. 21 DPR 633/1972): 12 days for an immediate invoice,
     * the 15th of the following month for a deferred one (TD24).
     */
    public static function issueDeadline(string $operationDate, bool $deferred = false): string
    {
        $d = new \DateTimeImmutable($operationDate);
        return $deferred
            ? $d->modify('first day of next month')->format('Y-m-15')
            : $d->modify('+12 days')->format('Y-m-d');
    }

    /** Suggested resend deadline after a rejection: 5 days from the rejection notice. */
    public static function resendDeadline(string $rejectedAt): string
    {
        return (new \DateTimeImmutable($rejectedAt))->modify('+5 days')->format('Y-m-d');
    }

    /** Marca da bollo: 2 € due when the VAT-exempt amount exceeds 77,47 €. */
    public static function stampDutyDue(float $exemptAmount): bool
    {
        return $exemptAmount > 77.47;
    }
}
