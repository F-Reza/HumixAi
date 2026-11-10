<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UrlImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UrlImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'studio.url_import_enabled' => true]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function service(array $dns = [], array $responses = []): UrlImportService
    {
        return new class($dns, $responses) extends UrlImportService {
            public array $connections = [];
            public int $resolutions = 0;
            public function __construct(private array $dns, private array $responses) {}
            protected function resolveAddresses(string $host, int $depth = 0, array $seen = []): array
            {
                $this->resolutions++;
                return $this->dns[$host] ?? ['93.184.215.14'];
            }
            protected function fetchPinned(string $url, string $host, int $port, string $ip): array
            {
                $this->connections[] = compact('url', 'host', 'port', 'ip');
                return $this->responses[$url] ?? ['status' => 200, 'content_type' => 'text/plain', 'body' => 'This is readable public page text.'];
            }
        };
    }

    private function assertRejected(UrlImportService $service, string $url): void
    {
        try { $service->preview($url); $this->fail('Unsafe URL should have been rejected.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('url', $exception->errors()); }
    }

    public function test_private_literals_obfuscated_hosts_credentials_and_nonweb_ports_never_connect(): void
    {
        foreach (['http://127.0.0.1/', 'http://[::1]/', 'http://2130706433/', 'http://0x7f000001/',
            'http://localhost/', 'http://metadata.google.internal/', 'http://user:pass@example.com/',
            'http://example.com:8080/', 'file:///private/path', 'https://example.com\\@localhost/'] as $url) {
            $service = $this->service();
            $this->assertRejected($service, $url);
            $this->assertSame([], $service->connections);
        }
    }

    public function test_mixed_dns_private_ipv4_ipv6_and_transition_addresses_are_rejected(): void
    {
        foreach (['10.0.0.1', '169.254.169.254', '100.64.0.1', '198.18.0.1', '::ffff:127.0.0.1',
            'fc00::1', 'fe80::1', '2002:7f00:1::', '2001:db8::1', '3fff::1'] as $ip) {
            $service = $this->service(['public.example.com' => ['93.184.215.14', $ip]]);
            $this->assertRejected($service, 'https://public.example.com/');
            $this->assertSame([], $service->connections);
        }
    }

    public function test_redirect_target_is_revalidated_and_public_connection_uses_approved_ip(): void
    {
        $service = $this->service(['public.example.com' => ['93.184.215.14'], 'internal.example.com' => ['127.0.0.1']],
            ['https://public.example.com/' => ['status' => 302, 'location' => 'https://internal.example.com/private', 'body' => '']]);
        $this->assertRejected($service, 'https://public.example.com/');
        $this->assertCount(1, $service->connections);
        $this->assertSame('93.184.215.14', $service->connections[0]['ip']);
        $this->assertSame(443, $service->connections[0]['port']);
        $this->assertSame(2, $service->resolutions);
        $downgrade = $this->service([], ['https://public.example.com/' => ['status' => 302, 'location' => 'http://public.example.com/', 'body' => '']]);
        $this->assertRejected($downgrade, 'https://public.example.com/');
        $this->assertCount(1, $downgrade->connections);
    }

    public function test_html_preview_extracts_body_paragraphs_without_running_scripts_or_starting_a_scan(): void
    {
        $service = $this->service([], ['https://public.example.com/article' => [
            'status' => 200, 'content_type' => 'text/html; charset=UTF-8',
            'body' => '<html><head><title>Public article</title></head><body><nav>Unrelated navigation</nav><article><p>First paragraph &amp; citation.</p><p>বাংলা লেখার অনুচ্ছেদ।</p><script>alert("never run")</script></article></body></html>',
        ]]);
        $this->app->instance(UrlImportService::class, $service);
        $this->postJson('/imports/url', ['url' => 'https://public.example.com/article'])->assertUnauthorized();
        $this->actingAs(User::create(['name' => 'Importer', 'email' => 'importer@example.test', 'password' => Hash::make('test-password-123')]));
        $this->postJson('/imports/url', ['url' => 'https://public.example.com/article'])->assertOk()
            ->assertJsonPath('data.title', 'Public article')->assertJsonPath('data.format', 'url')
            ->assertJsonPath('data.text', "First paragraph & citation.\n\nবাংলা লেখার অনুচ্ছেদ।");
        $this->assertCount(1, $service->connections);
        $this->assertDatabaseCount('studio_jobs', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_oversized_binary_and_redirect_loops_fail_without_truncation(): void
    {
        config(['studio.url_import_max_bytes' => 1024]);
        $large = $this->service([], ['https://public.example.com/' => ['status' => 200, 'content_type' => 'text/plain', 'body' => str_repeat('A', 1025)]]);
        $this->assertRejected($large, 'https://public.example.com/');
        $binary = $this->service([], ['https://public.example.com/' => ['status' => 200, 'content_type' => 'application/octet-stream', 'body' => "binary\0text"]]);
        $this->assertRejected($binary, 'https://public.example.com/');
        $loop = $this->service([], ['https://public.example.com/' => ['status' => 302, 'location' => '/', 'body' => '']]);
        $this->assertRejected($loop, 'https://public.example.com/');
        $this->assertCount(1, $loop->connections);
    }
}
