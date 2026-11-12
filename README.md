<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/fiscozen-logo-white.svg">
    <img src="assets/fiscozen-logo-black.svg" alt="Fiscozen" width="320">
  </picture>
</p>

<h1 align="center">fiscozen-api</h1>

<p align="center">
  Unofficial PHP client and <b>MCP server</b> for <a href="https://www.fiscozen.it">Fiscozen</a>: invoices, customers, expenses, taxes and documents for Italian freelancers (partita IVA), usable from code or from Claude and ChatGPT.
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
  <img alt="MCP" src="https://img.shields.io/badge/MCP-2025--06--18-black">
  <img alt="Transports" src="https://img.shields.io/badge/transport-stdio%20%7C%20Streamable%20HTTP-2ea44f">
  <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue">
</p>

<p align="center"><a href="https://gabriele-wink.github.io/fiscozen-api/"><b>Landing page (italiano)</b></a></p>

> [!IMPORTANT]
> **Progetto non ufficiale.** fiscozen-api è un progetto open source indipendente: non è sviluppato, approvato né supportato da Fiscozen S.p.A. e non ha alcun legame con l'azienda.

> [!WARNING]
> This project is **not affiliated with, endorsed by or supported by Fiscozen S.p.A.** It uses the private web API of `app.fiscozen.it`, found by observing the web app, and it may break without notice. The Fiscozen name and logo belong to their owners and appear here only to identify the service. Invoices created and sent to SDI are legally binding: review what you automate.

---

## Features

### Authentication
- [x] Email and password login (django-allauth headless)
- [x] SMS two-factor authentication. The client never bypasses it and never registers a trusted device, so **a new SMS code is required every time the session expires**: unattended automation stops until someone enters it
- [x] Session status check and logout

### Invoices (*fatture emesse*)
- [x] List invoices by section: archive, to collect (*da incassare*), to send (*da inviare*), with pagination
- [x] Invoice detail: lines, totals, customer, SDI status
- [x] Download the PDF and the FatturaPA XML
- [x] **Server-side preview** (`compute_data`): totals, next number, VAT/nature codes, mandatory notes, stamp duty. Nothing is created
- [x] **Create** a numbered invoice (not sent)
- [x] **Update** and **delete** invoices not yet sent
- [x] **Send to SDI** (opt-in in the MCP server)
- [x] Mark an invoice as paid
- [x] Next invoice number per year, collection methods (IBAN...), VAT codes for your fiscal regime, document reasons (TD01, TD06...)

### Customers and suppliers (*clienti / fornitori*)
- [x] List and search customers and suppliers
- [x] Find a customer by VAT number or *codice fiscale*
- [x] **Create**, **update** (versioned records) and **delete**

### Other data
- [x] Credit notes: list, detail, PDF and XML
- [x] Occasional-work receipts (*prestazioni occasionali*) with PDF
- [x] Expenses and received invoices (*fatture passive*), with date range and search
- [x] Tax deadlines (F24), upcoming and past, and tax forecasts
- [x] Tax returns (*dichiarazioni*)
- [x] Document archive with download
- [x] In-app notifications
- [x] Profile, subscriptions, fiscal regime, real-time yearly turnover

### MCP server (Claude, ChatGPT and any MCP client)
- [x] 33 tools, plus `send_invoice_to_sdi` when enabled
- [x] **stdio** transport (Claude Desktop, Claude Code, Codex CLI, Cursor...)
- [x] **Streamable HTTP** transport (ChatGPT connectors, OpenAI Responses API, Claude API MCP connector)
- [x] `search` and `fetch` tools in the shape ChatGPT connectors and deep research expect
- [x] Read-only mode, with SDI sending disabled by default
- [x] Login from the chat: the assistant asks you for the SMS code
- [x] PDFs and documents returned as embedded files
- [x] No dependencies beyond Guzzle

---

## Install

```bash
git clone <this-repo> fiscozen-api && cd fiscozen-api
composer install
printf '%s\n%s\n' 'you@example.com' 'your-password' > .credentials.txt   # line 1 email, line 2 password
chmod 600 .credentials.txt
php bin/login.php           # sends the SMS code
php bin/login.php 123456    # confirms it, and the session is saved to .session.json
```

`.credentials.txt` and `.session.json` are in `.gitignore`. Never commit them.

---

## PHP library

```php
use FiscozenApi\Fiscozen;

$fz = new Fiscozen('.session.json');

// read
foreach ($fz->allInvoices(Fiscozen::SECTION_TO_COLLECT) as $inv) {
    echo "{$inv['full_id']}  {$inv['amount']} EUR  {$inv['counterpart_version_display_name']}\n";
}
file_put_contents('invoice.pdf', $fz->invoicePdf($id));

// customer
$c = $fz->findCustomer('12345678901') ?? $fz->createCustomer([
    'typology' => 'SOCI', 'business_name' => 'ACME S.R.L.', 'vat' => '12345678901',
    'address' => 'Via Roma 1', 'municipality' => 'Milano', 'postcode' => '20100', 'province' => 'MI',
    'country' => 'IT', 'recipient_code' => 'ABC1234',
]);

// invoice: preview -> create -> (optionally) send
$payload = $fz->buildInvoice($c['counterpart_version'], [
    ['description' => 'Pro plan subscription - November 2026', 'amount' => 49.90],
]);
$preview = $fz->computeInvoice($payload);   // full_id, total_amount, all_notes... nothing is created
$inv     = $fz->createInvoice($payload);    // numbered, status DA_INCASSARE / e_status TO_SEND
// $fz->sendInvoiceToSdi($inv['id']);       // IRREVERSIBLE
```

<details>
<summary><b>All methods</b></summary>

| Area | Methods |
| --- | --- |
| Auth | `login()`, `verifyMfa()`, `isAuthenticated()`, `session()`, `logout()` |
| Profile | `me()`, `userId()`, `subscriptions()`, `extraServices()`, `featureFlags()`, `addresses()`, `fiscalRegimes()`, `yearStats()` |
| Invoices | `listInvoices()`, `allInvoices()`, `invoice()`, `invoicePdf()`, `invoiceXml()`, `invoiceNumbering()`, `invoicingSettings()`, `invoicePaymentMethods()` |
| Invoicing | `buildInvoice()`, `computeInvoice()`, `createInvoice()`, `updateInvoice()`, `deleteInvoice()`, `invoicePreviewXml()`, `sendInvoiceToSdi()`, `markInvoicePaid()`, `vatCodes()`, `vatCode()`, `defaultVatCode()`, `reasons()`, `invoiceFormatInfo()` |
| Credit notes | `listCreditNotes()`, `allCreditNotes()`, `creditNote()`, `creditNotePdf()`, `creditNoteXml()` |
| Receipts | `allReceipts()`, `receiptPdf()` |
| Expenses | `listExpenses()`, `allExpenses()`, `expense()` |
| Customers / suppliers | `allCustomers()`, `allProviders()`, `counterpart()`, `counterpartByVersion()`, `findCustomer()`, `createCustomer()`, `createProvider()`, `updateCounterpart()`, `deleteCounterpart()`, `counterpartChoices()` |
| Taxes | `taxPayments()`, `taxForecasts()`, `taxDeclarations()` |
| Documents | `allDocuments()`, `downloadDocument()` |
| Notifications | `allNotifications()` |
| Low level | `get()`, `post()`, `patch()`, `delete()`, `request()`, `download()`, `paginate()` |

`list*` methods return one DRF page (`count`, `next`, `results`). `all*` methods return a `Generator` over every page.

</details>

<details>
<summary><b>Rules enforced by Fiscozen</b></summary>

- Customer `typology`: `PRIV` private person, `PROF` professional, `SOCI` company, `PA` public administration, `NOPR` non-profit.
- An Italian company requires a valid `vat`. An Italian private person requires a valid `ssn` (*codice fiscale*). Foreign customers use `recipient_code` `XXXXXXX`.
- Customer records are versioned: an update creates a new `counterpart_version`, and existing invoices keep the old one.
- A line's `vat_code` accepts a code string such as `NI3`, `VAT22` or `RC02`. The default comes from the fiscal regime: flat-rate `NI3`, *minimi* `NS27`, ordinary `VAT22`.
- `compute_data` and create return HTTP 500 if the `update_*_from_db` flags are sent. `buildInvoice()` omits them.

</details>

---

## MCP server

### Tools

| Tool | Kind | What it does |
| --- | --- | --- |
| `auth_status`, `login_start`, `login_verify` | auth | Session check and SMS login from the chat |
| `get_profile`, `get_year_stats` | read | Business profile, turnover vs. the flat-rate ceiling |
| `list_invoices`, `get_invoice`, `get_invoice_pdf`, `get_invoice_xml`, `get_invoice_numbering` | read | Issued invoices |
| `list_credit_notes`, `get_credit_note_pdf` | read | Credit notes |
| `list_customers`, `list_providers`, `get_customer`, `find_customer` | read | Customers and suppliers |
| `list_expenses`, `list_tax_payments`, `get_tax_forecasts` | read | Expenses and taxes |
| `list_documents`, `get_document` | read | Document archive |
| `list_payment_methods`, `list_vat_codes` | read | Lookups for invoicing |
| `search`, `fetch` | read | ChatGPT connector and deep research format |
| `preview_invoice` | read | Server-side computation, nothing created |
| `create_customer`, `update_customer` | write | Customers |
| `create_invoice`, `update_invoice`, `mark_invoice_paid` | write | Invoices (not sent) |
| `delete_customer`, `delete_invoice` | destructive | Only for records that can still be deleted |
| `send_invoice_to_sdi` | **irreversible** | Only with `FISCOZEN_MCP_ALLOW_SDI=1`, and requires `confirm: true` |

Every tool carries MCP annotations (`readOnlyHint`, `destructiveHint`), so clients can ask you before running write tools.

### Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `FISCOZEN_SESSION_FILE` | `./.session.json` | Session cookie file |
| `FISCOZEN_EMAIL`, `FISCOZEN_PASSWORD` | – | Credentials for `login_start` |
| `FISCOZEN_CREDENTIALS_FILE` | `./.credentials.txt` | Alternative to the two variables above |
| `FISCOZEN_MCP_READONLY` | `0` | `1` exposes read tools only |
| `FISCOZEN_MCP_ALLOW_SDI` | `0` | `1` exposes `send_invoice_to_sdi` |
| `FISCOZEN_MCP_TOKEN` | – | **Required** for HTTP: bearer token |
| `FISCOZEN_MCP_ALLOW_QUERY_TOKEN` | `0` | `1` also accepts `?key=<token>`, for UIs that can't send headers |
| `FISCOZEN_MCP_ALLOWED_ORIGINS` | chatgpt.com, claude.ai | Allowed `Origin` values (DNS-rebinding protection) |

### Claude Desktop

`claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "fiscozen": {
      "command": "php",
      "args": ["/absolute/path/fiscozen-api/bin/mcp-server.php"],
      "env": { "FISCOZEN_MCP_READONLY": "0" }
    }
  }
}
```

### Claude Code

```bash
claude mcp add fiscozen -- php /absolute/path/fiscozen-api/bin/mcp-server.php
```

### Codex CLI / Cursor

```toml
# ~/.codex/config.toml
[mcp_servers.fiscozen]
command = "php"
args = ["/absolute/path/fiscozen-api/bin/mcp-server.php"]
```

```json
// .cursor/mcp.json
{ "mcpServers": { "fiscozen": { "command": "php", "args": ["/absolute/path/fiscozen-api/bin/mcp-server.php"] } } }
```

### Remote HTTP server (ChatGPT, OpenAI API, Claude API)

```bash
FISCOZEN_MCP_TOKEN=$(openssl rand -hex 32) php -S 127.0.0.1:8787 public/mcp.php
# endpoint: http://127.0.0.1:8787/mcp   header: Authorization: Bearer <token>
```

For production, serve `public/mcp.php` behind HTTPS with nginx, Caddy or Apache, using any PHP-FPM setup.

**ChatGPT** (Settings, then Connectors, then Developer mode, then Add custom connector): the UI only supports OAuth or no authentication. Start the server with `FISCOZEN_MCP_ALLOW_QUERY_TOKEN=1` and use the URL `https://your-host/mcp?key=<token>`.

**OpenAI Responses API**:

```python
client.responses.create(
    model="gpt-5",
    tools=[{
        "type": "mcp", "server_label": "fiscozen",
        "server_url": "https://your-host/mcp",
        "headers": {"Authorization": "Bearer <token>"},
        "require_approval": {"never": {"tool_names": ["list_invoices", "get_invoice", "preview_invoice"]}},
    }],
    input="How much did I invoice this year, and which invoices are still unpaid?",
)
```

**Claude API** (MCP connector):

```python
client.beta.messages.create(
    model="claude-opus-5-5", max_tokens=4096,
    mcp_servers=[{"type": "url", "url": "https://your-host/mcp", "name": "fiscozen",
                  "authorization_token": "<token>"}],
    messages=[{"role": "user", "content": "Prepare (do not send) an invoice for ACME for 49.90 EUR"}],
    betas=["mcp-client-2025-04-04"],
)
```

**claude.ai** (custom connector): same limitation as ChatGPT. Use the `?key=` URL.

---

## CLI

```bash
php bin/login.php [sms-code]                                   # login
php bin/smoke.php                                              # read-only test of every method
php bin/invoice.php <vat|cf> "<description>" <amount> --dry-run  # preview only
php bin/invoice.php <vat|cf> "<description>" <amount>            # create + save the PDF
php bin/invoice.php <vat|cf> "<description>" <amount> --send     # create + send to SDI (irreversible)
php bin/mcp-server.php                                         # MCP stdio server
```

## Project layout

```
src/
  FiscozenClient.php     auth (allauth + SMS 2FA), session, HTTP transport, pagination
  Fiscozen.php           domain methods (invoices, customers, taxes, documents...)
  Mcp/McpServer.php      dependency-free MCP JSON-RPC server
  Mcp/FiscozenTools.php  MCP tool definitions
bin/                     CLI tools and the stdio MCP server
public/mcp.php           Streamable HTTP MCP endpoint
assets/                  logo
```

## Test status

| Area | Status |
| --- | --- |
| All read methods and downloads | live-tested: 26/26 pass |
| `createCustomer`, `updateCounterpart`, `deleteCounterpart` | live-tested with a throwaway customer |
| `computeInvoice`, `createInvoice`, `invoicePdf`, `updateInvoice`, `deleteInvoice` | live-tested with a 1 € invoice, then deleted; numbering restored |
| MCP stdio and HTTP: initialize, tools/list, tools/call, auth, origin check | tested |
| `sendInvoiceToSdi`, `markInvoicePaid`, `invoicePreviewXml` | **not tested live** (real tax submission) |

## Security notes

- The session file grants full access to the Fiscozen account. Keep it private: the library writes it with mode `0600`.
- The HTTP server refuses to start without `FISCOZEN_MCP_TOKEN`. Serve it over HTTPS only.
- Keep `FISCOZEN_MCP_ALLOW_SDI` off unless you need it. Even when it's on, the tool requires `confirm: true`.
- Prefer `FISCOZEN_MCP_READONLY=1` for assistants that only need to answer questions.

## License

[MIT](LICENSE). The Fiscozen name and logo are trademarks of their owners and are not covered by the license.
