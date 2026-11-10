<?php

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Imports public page text without submitting it to a model or following unvalidated redirects. */
class UrlImportService
{
    public function capabilities(): array
    {
        $enabled = (bool) config('studio.url_import_enabled', true) && extension_loaded('curl') && function_exists('dns_get_record');
        return ['url' => $enabled, 'url_import' => $enabled, 'max_bytes' => $this->maxBytes(),
            'redirect_limit' => 3, 'authenticated_pages' => false, 'javascript_rendering' => false];
    }

    public function preview(string $url): array
    {
        if (!$this->capabilities()['url']) { $this->invalid('URL import is unavailable on this server. Upload a TXT or DOCX file instead.'); }
        $visited = [];
        for ($hop = 0; $hop <= 3; $hop++) {
            $target = $this->target($url);
            if (isset($visited[$target['url']])) { $this->invalid('The page redirects in a loop.'); }
            $visited[$target['url']] = true;
            $addresses = $this->resolveAddresses($target['host']);
            if (!$addresses || count($addresses) > 64) { $this->invalid('The host could not be resolved safely.'); }
            foreach ($addresses as $address) {
                if (!$this->publicAddress($address)) { $this->invalid('Only public internet hosts can be imported.'); }
            }
            // Every possible DNS answer is checked. One approved answer is pinned for the connection.
            $ip = $addresses[0];
            try { $response = $this->fetchPinned($target['url'], $target['host'], $target['port'], $ip); }
            catch (Throwable) { $this->invalid('The page could not be downloaded within the safe import limits. Upload its text instead.'); }
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                if ($hop === 3 || !is_string($response['location'] ?? null) || strlen($response['location']) > 4096) {
                    $this->invalid('The page has too many redirects or an invalid redirect address.');
                }
                try { $next = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response['location'])); }
                catch (Throwable) { $this->invalid('The redirect address is invalid.'); }
                if ($target['scheme'] === 'https' && strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https') {
                    $this->invalid('A secure page cannot redirect to an insecure address.');
                }
                $url = $next;
                continue;
            }
            if ($response['status'] !== 200) { $this->invalid('The page did not return readable public content. Sign-in and error pages are not supported.'); }
            $type = strtolower(trim(explode(';', $response['content_type'] ?? '')[0]));
            if (!in_array($type, ['text/html', 'text/plain'], true)) { $this->invalid('Only HTML pages and plain text URLs are supported.'); }
            $body = $response['body'] ?? '';
            if (!is_string($body) || strlen($body) > $this->maxBytes()) { $this->invalid('The downloaded page is too large.'); }
            if (!mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) { $this->invalid('The page must contain UTF-8 text. Download it as a UTF-8 text file instead.'); }
            [$text, $title] = $type === 'text/html' ? $this->htmlText($body) : [trim($body), $target['host']];
            if (trim($text) === '') { $this->invalid('No readable page text was found. JavaScript-only pages require a manual text copy.'); }
            if (mb_strlen($text, 'UTF-8') > (int) config('studio.max_characters', 60000)) { $this->invalid('The page text exceeds the document limit. Copy a smaller section instead.'); }
            preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\x{200C}\x{200D}\x{2019}\x{0027}-]*/u', $text, $matches);
            return ['text' => $text, 'title' => mb_substr($title ?: $target['host'], 0, 200, 'UTF-8'),
                'source_url' => $target['url'], 'format' => 'url', 'word_count' => count($matches[0]),
                'character_count' => mb_strlen($text, 'UTF-8'), 'capabilities' => $this->capabilities(),
                'warnings' => ['Review extracted text before scanning. Navigation, images and visual formatting may be omitted.',
                    'This fetch does not run JavaScript or access signed-in pages. No analysis provider was contacted.']];
        }
        $this->invalid('The page could not be imported.');
    }

    protected function resolveAddresses(string $host, int $depth = 0, array $seen = []): array
    {
        if ($depth > 5 || isset($seen[$host])) { return []; }
        $seen[$host] = true;
        $records = @dns_get_record($host.'.', DNS_A | DNS_AAAA | DNS_CNAME);
        if (!is_array($records) || count($records) > 64) { return []; }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) { $addresses[] = $record['ip']; }
            if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
            if (($record['type'] ?? '') === 'CNAME') {
                $alias = $this->hostname((string) ($record['target'] ?? ''));
                $addresses = array_merge($addresses, $this->resolveAddresses($alias, $depth + 1, $seen));
            }
        }
        return array_values(array_unique($addresses));
    }

    protected function fetchPinned(string $url, string $host, int $port, string $ip): array
    {
        $max = $this->maxBytes();
        $stream = Utils::streamFor(fopen('php://temp/maxmemory:1048576', 'w+'));
        $bounded = FnStream::decorate($stream, ['write' => function (string $bytes) use ($stream, $max): int {
            if (($stream->getSize() ?? 0) + strlen($bytes) > $max) { throw new RuntimeException('Page exceeds download limit.'); }
            return $stream->write($bytes);
        }]);
        $client = new Client(['handler' => HandlerStack::create(new CurlHandler())]);
        try {
            $response = $client->request('GET', $url, [
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true, 'proxy' => '',
                'connect_timeout' => 5, 'timeout' => 15, 'decode_content' => false, 'sink' => $bounded,
                'headers' => ['Accept' => 'text/html, text/plain;q=0.9', 'Accept-Encoding' => 'identity', 'User-Agent' => 'HumixAi-Text-Import/1.0'],
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
                    CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_FOLLOWLOCATION => false],
                'on_headers' => function (ResponseInterface $response) use ($max): void {
                    if (strlen($response->getHeaderLine('Location')) > 4096) { throw new RuntimeException('Redirect address too large.'); }
                    $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                    if ($encoding !== '' && $encoding !== 'identity') { throw new RuntimeException('Compressed responses are not imported.'); }
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (!ctype_digit($length) || (int) $length > $max)) { throw new RuntimeException('Page exceeds download limit.'); }
                    if ($response->getStatusCode() === 200) {
                        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
                        if (!in_array($type, ['text/html', 'text/plain'], true)) { throw new RuntimeException('Unsupported page content type.'); }
                    }
                },
            ]);
            $stream->rewind();
            return ['status' => $response->getStatusCode(), 'location' => $response->getHeaderLine('Location'),
                'content_type' => $response->getHeaderLine('Content-Type'), 'body' => $stream->getContents()];
        } finally { $bounded->close(); }
    }

    private function target(string $url): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) { $this->invalid('Enter a valid public HTTP or HTTPS URL without spaces or credentials.'); }
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { $this->invalid('Only public HTTP and HTTPS pages without embedded credentials are supported.'); }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== ($scheme === 'https' ? 443 : 80)) { $this->invalid('Only standard web ports 80 and 443 are supported.'); }
        $host = $this->hostname($parts['host']);
        $path = $parts['path'] ?? '/';
        $url = $scheme.'://'.$host.($path === '' ? '/' : $path).(isset($parts['query']) ? '?'.$parts['query'] : '');
        return compact('url', 'host', 'port', 'scheme');
    }

    private function hostname(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        if (preg_match('/[^\x20-\x7E]/', $host)) {
            $host = function_exists('idn_to_ascii') ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '') : '';
        }
        if ($host === '' || strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host)
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            || preg_match('/\.(?:localhost|local|internal|home|test|invalid|example|onion|arpa|lan|corp|intranet)$/D', $host)) {
            $this->invalid('Use a public domain name. Local hosts, IP addresses and reserved domains are not supported.');
        }
        return $host;
    }

    private function publicAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
                '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $range) { if ($this->inRange($ip, $range)) { return false; } }
            return true;
        }
        if (!$this->inRange($ip, '2000::/3')) { return false; }
        foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) { if ($this->inRange($ip, $range)) { return false; } }
        return true;
    }

    private function inRange(string $ip, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $address = inet_pton($ip); $base = inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) { return false; }
        $full = intdiv((int) $bits, 8); $remaining = (int) $bits % 8;
        return substr($address, 0, $full) === substr($base, 0, $full)
            && ($remaining === 0 || (ord($address[$full]) & (0xFF << (8 - $remaining))) === (ord($base[$full]) & (0xFF << (8 - $remaining))));
    }

    private function htmlText(string $html): array
    {
        if (preg_match('/<!\s*ENTITY\b/i', $html)) { $this->invalid('HTML entity declarations are not supported.'); }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument(); $dom->resolveExternals = false; $dom->substituteEntities = false;
            if (!$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT)) { $this->invalid('The page HTML could not be read.'); }
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//svg|//iframe|//form|//template') as $node) { $node->parentNode?->removeChild($node); }
            $title = trim($dom->getElementsByTagName('title')->item(0)?->textContent ?? '');
            $root = $dom->getElementsByTagName('article')->item(0) ?? $dom->getElementsByTagName('main')->item(0) ?? $dom->getElementsByTagName('body')->item(0);
            $text = $root ? $this->nodeText($root) : '';
            $text = preg_replace('/[^\S\n]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $text));
            $text = preg_replace('/ *\n */u', "\n", $text ?? '');
            return [trim(preg_replace('/\n{3,}/u', "\n\n", $text ?? '') ?? ''), $title];
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private function nodeText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) { return $node->textContent; }
        $block = in_array(strtolower($node->nodeName), ['p', 'div', 'article', 'section', 'main', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'tr', 'br'], true);
        $text = $block ? "\n\n" : '';
        foreach ($node->childNodes as $child) { $text .= $this->nodeText($child); }
        return $text.($block ? "\n\n" : '');
    }

    private function maxBytes(): int { return max(1024, min(2 * 1024 * 1024, (int) config('studio.url_import_max_bytes', 1024 * 1024))); }
    private function invalid(string $message): never { throw ValidationException::withMessages(['url' => [$message]]); }
}
