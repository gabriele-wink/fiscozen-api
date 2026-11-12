<?php

declare(strict_types=1);

namespace FiscozenApi;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Unofficial client for Fiscozen (app.fiscozen.it).
 *
 * Auth is django-allauth "headless" (browser client): session cookie + CSRF token.
 * The account has SMS two-factor auth, so login is two steps:
 *
 *   $fz = new FiscozenClient('.session.json');
 *   if (!$fz->isAuthenticated()) {
 *       $r = $fz->login($email, $password);   // sends the SMS when MFA is pending
 *       if ($r['mfa_required']) $fz->verifyMfa($codeFromSms);
 *   }
 *
 * Cookies are persisted in the given file, so the SMS is needed only when the session expires.
 */
class FiscozenClient
{
    public const BASE = 'https://app.fiscozen.it';
    private const AUTH = '/api-auth/browser/v1';

    private Client $http;
    private FileCookieJar $jar;

    public function __construct(string $sessionFile = '.session.json', float $timeout = 30.0)
    {
        $this->jar = new FileCookieJar($sessionFile, true);
        @chmod($sessionFile, 0600);
        $this->http = new Client([
            'base_uri' => self::BASE,
            'cookies' => $this->jar,
            'timeout' => $timeout,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0 Safari/537.36',
                'Accept' => 'application/json',
                'Accept-Language' => 'it-IT,it;q=0.9',
                'Referer' => self::BASE . '/app/',
                'Origin' => self::BASE,
            ],
        ]);
    }

    public static function fromCredentialsFile(string $credFile, string $sessionFile = '.session.json'): array
    {
        $lines = array_map('trim', file($credFile, FILE_IGNORE_NEW_LINES));
        return [new static($sessionFile), $lines[0], $lines[1]];
    }

    // ------------------------------------------------------------------ auth

    /** @return array{authenticated: bool, mfa_required: bool, mfa_types: string[], raw: array} */
    public function login(string $email, string $password): array
    {
        $this->ensureCsrf();
        $data = $this->raw('POST', self::AUTH . '/auth/login', ['json' => ['email' => $email, 'password' => $password]]);
        return $this->authState($data);
    }

    /** Submit the SMS code received after login(). */
    public function verifyMfa(string $code): array
    {
        $data = $this->raw('POST', self::AUTH . '/auth/2fa/authenticate', ['json' => ['code' => trim($code)]]);
        $state = $this->authState($data);
        // After a correct code allauth may ask "trust this browser?" (mfa_trust flow).
        // Answer no: the login completes without registering a trusted device on the account.
        if (!$state['authenticated'] && $this->hasPendingFlow($data, 'mfa_trust')) {
            $data = $this->raw('POST', self::AUTH . '/auth/2fa/trust', ['json' => ['trust' => false]]);
            $state = $this->authState($data);
        }
        if (!$state['authenticated']) {
            throw new FiscozenException('Codice MFA non accettato', $data);
        }
        return $state;
    }

    public function session(): array
    {
        return $this->authState($this->raw('GET', self::AUTH . '/auth/session'));
    }

    public function isAuthenticated(): bool
    {
        return $this->session()['authenticated'];
    }

    public function logout(): void
    {
        $this->raw('DELETE', self::AUTH . '/auth/session');
        $this->jar->clear();
    }

    private function authState(array $data): array
    {
        $flows = $data['data']['flows'] ?? [];
        $mfa = array_values(array_filter($flows, fn($f) => ($f['id'] ?? '') === 'mfa_authenticate' && !empty($f['is_pending'])));
        return [
            'authenticated' => (bool)($data['meta']['is_authenticated'] ?? false),
            'mfa_required' => (bool)$mfa,
            'mfa_types' => $mfa[0]['types'] ?? [],
            'raw' => $data,
        ];
    }

    private function hasPendingFlow(array $data, string $id): bool
    {
        foreach ($data['data']['flows'] ?? [] as $f) {
            if (($f['id'] ?? '') === $id && !empty($f['is_pending'])) {
                return true;
            }
        }
        return false;
    }

    private function ensureCsrf(): void
    {
        if ($this->csrf() === null) {
            $this->http->request('GET', '/app/login', ['headers' => ['Accept' => 'text/html']]);
        }
    }

    private function csrf(): ?string
    {
        foreach ($this->jar->toArray() as $c) {
            if ($c['Name'] === 'csrftoken') {
                return $c['Value'];
            }
        }
        return null;
    }

    // ------------------------------------------------------------- transport

    /** Auth endpoints answer 401 with a JSON body describing pending flows: return it instead of throwing. */
    private function raw(string $method, string $path, array $opts = []): array
    {
        $opts['headers'] = ($opts['headers'] ?? []) + array_filter(['X-CSRFToken' => $this->csrf()]);
        $res = $this->http->request($method, $path, $opts);
        $body = json_decode((string)$res->getBody(), true);
        if ($res->getStatusCode() >= 500 || !is_array($body)) {
            if ($res->getStatusCode() === 204) {
                return [];
            }
            throw new FiscozenException("HTTP {$res->getStatusCode()} on $method $path", ['body' => (string)$res->getBody()]);
        }
        return $body;
    }

    /** Generic call to the app REST API (``/api/v1/...``). Throws on HTTP >= 400. */
    public function request(string $method, string $path, array $query = [], ?array $json = null): mixed
    {
        $opts = ['headers' => array_filter(['X-CSRFToken' => $this->csrf()])];
        if ($query) {
            $opts['query'] = $query;
        }
        if ($json !== null) {
            $opts['json'] = $json;
        }
        $res = $this->http->request($method, $path, $opts);
        return $this->decode($res, "$method $path");
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $json = [], array $query = []): mixed
    {
        return $this->request('POST', $path, $query, $json);
    }

    public function patch(string $path, array $json): mixed
    {
        return $this->request('PATCH', $path, [], $json);
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path);
    }

    /** Download a binary resource (PDF, XML...). */
    public function download(string $path, array $query = []): string
    {
        $res = $this->http->request('GET', $path, ['query' => $query, 'headers' => ['Accept' => '*/*']]);
        if ($res->getStatusCode() >= 400) {
            throw new FiscozenException("HTTP {$res->getStatusCode()} on GET $path", ['body' => substr((string)$res->getBody(), 0, 500)]);
        }
        return (string)$res->getBody();
    }

    /** Iterate a DRF-style paginated list ({count, next, results}). */
    public function paginate(string $path, array $query = []): \Generator
    {
        $next = $path;
        while ($next) {
            $page = $this->get($next, $query);
            $query = [];
            if (!is_array($page) || !array_key_exists('results', $page)) {
                yield from (array)$page;
                return;
            }
            yield from $page['results'];
            $next = !empty($page['next']) ? preg_replace('#^https?://[^/]+#', '', $page['next']) : null;
        }
    }

    private function decode(ResponseInterface $res, string $what): mixed
    {
        $code = $res->getStatusCode();
        $text = (string)$res->getBody();
        $data = $text === '' ? null : json_decode($text, true);
        if ($code === 401 || $code === 403) {
            throw new FiscozenAuthException("Non autenticato ($code) su $what", is_array($data) ? $data : ['body' => $text]);
        }
        if ($code >= 400) {
            throw new FiscozenException("HTTP $code su $what", is_array($data) ? $data : ['body' => substr($text, 0, 500)]);
        }
        return $data ?? $text;
    }
}
