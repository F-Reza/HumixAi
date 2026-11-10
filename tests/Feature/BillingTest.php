<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function writer(): User
    {
        return User::create(['name' => 'Billing Writer', 'email' => 'billing@example.test', 'password' => 'long-test-password']);
    }

    private function configure(): void
    {
        config(['billing.enabled' => true, 'billing.secret_key' => 'sk_test_local', 'billing.webhook_secret' => 'whsec_local',
            'billing.api_version' => null, 'billing.plans.pro.price_id' => 'price_local', 'billing.plans.pro.daily_word_limit' => 100000]);
    }

    private function account(User $user, array $attributes = []): void
    {
        DB::table('billing_accounts')->insert($attributes + ['user_id' => $user->id, 'customer_id' => 'cus_local',
            'status' => 'none', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function subscription(string $status = 'active', string $id = 'sub_local'): array
    {
        return ['id' => $id, 'customer' => 'cus_local', 'status' => $status,
            'items' => ['data' => [['price' => ['id' => 'price_local']]], 'has_more' => false]];
    }

    private function checkoutSession(string $status = 'open', string $id = 'cs_local'): array
    {
        return ['id' => $id, 'customer' => 'cus_local', 'mode' => 'subscription', 'status' => $status,
            'url' => $status === 'open' ? 'https://checkout.stripe.com/c/pay/'.$id : null,
            'metadata' => ['humixai_plan' => 'pro'], 'expires_at' => now()->addMinutes(30)->timestamp,
            'subscription' => $status === 'complete' ? 'sub_local' : null];
    }

    private function listing(array $items): array { return ['data' => $items, 'has_more' => false]; }

    private function sendEvent(array $event, ?string $signature = null)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR); $time = time();
        $signature ??= 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, 'whsec_local');
        return $this->call('POST', '/api/billing/webhook', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    }

    private function event(string $id, string $type = 'customer.subscription.updated', string $snapshotStatus = 'active', ?int $created = null): array
    {
        return ['id' => $id, 'type' => $type, 'created' => $created ?? time(), 'data' => ['object' => $this->subscription($snapshotStatus)]];
    }

    public function test_billing_is_explicitly_unavailable_without_configuration(): void
    {
        config(['billing.enabled' => false, 'billing.secret_key' => null]);
        $this->actingAs($this->writer())->getJson('/billing/status')->assertOk()->assertJsonPath('data.configured', false);
        $this->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_checkout_uses_server_price_and_does_not_activate_a_plan_on_redirect(): void
    {
        $this->configure(); $user = $this->writer();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            return match (true) {
                $path === '/v1/customers' => Http::response(['id' => 'cus_local']),
                $path === '/v1/subscriptions' => Http::response($this->listing([])),
                $path === '/v1/checkout/sessions' && $request->method() === 'GET' => Http::response($this->listing([])),
                $path === '/v1/checkout/sessions' && $request->method() === 'POST' => Http::response($this->checkoutSession()),
                default => Http::response([], 404),
            };
        });
        $this->actingAs($user)->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true, 'price_id' => 'price_attack'])
            ->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_local')->assertDontSee('sk_test_local');
        $this->assertSame('free', $user->fresh()->plan);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/checkout/sessions')
            && $request['line_items'][0]['price'] === 'price_local' && $request->hasHeader('Idempotency-Key') && !$request->hasHeader('Stripe-Version'));
        $this->assertDatabaseHas('billing_accounts', ['user_id' => $user->id, 'checkout_session_id' => 'cs_local', 'checkout_status' => 'open', 'checkout_request_key' => null]);
        $this->get('/?billing=success')->assertOk();
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_open_checkout_is_reused_after_five_minutes_without_creating_another_subscription_session(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user); $created = false; $posts = 0;
        Http::fake(function ($request) use (&$created, &$posts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/subscriptions') { return Http::response($this->listing([])); }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'GET') { return Http::response($this->listing($created ? [$this->checkoutSession()] : [])); }
            if ($path === '/v1/checkout/sessions' && $request->method() === 'POST') { $posts++; $created = true; return Http::response($this->checkoutSession()); }
            return Http::response([], 404);
        });
        $this->actingAs($user)->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertOk();
        $this->travel(10)->minutes();
        $this->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertOk()
            ->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_local');
        $this->assertSame(1, $posts);
    }

    public function test_webhook_reconciles_current_remote_state_even_for_same_second_out_of_order_snapshots(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user); $currentStatus = 'active'; $reads = 0;
        Http::fake(function ($request) use (&$currentStatus, &$reads) {
            $reads++;
            return Http::response($this->listing([$this->subscription($currentStatus)]));
        });
        $time = time();
        $this->sendEvent($this->event('evt_current', created: $time))->assertOk();
        $this->sendEvent($this->event('evt_stale_same_second', snapshotStatus: 'canceled', created: $time))->assertOk();
        $this->assertSame('pro', $user->fresh()->plan);
        $this->assertSame(100000, $user->fresh()->daily_word_limit);
        $this->sendEvent($this->event('evt_stale_same_second', snapshotStatus: 'canceled', created: $time))->assertOk();
        $this->assertSame(2, $reads);
        $currentStatus = 'paused';
        $this->sendEvent($this->event('evt_paused', 'customer.subscription.paused', 'active', $time - 100))->assertOk();
        $this->assertSame('free', $user->fresh()->plan);
        $currentStatus = 'active';
        $this->sendEvent($this->event('evt_resumed', 'customer.subscription.resumed', 'paused', $time - 100))->assertOk();
        $this->assertSame('pro', $user->fresh()->plan);
        $this->assertDatabaseCount('billing_events', 4);
    }

    public function test_invalid_signatures_and_remote_failures_do_not_mutate_or_acknowledge_state(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user);
        $this->sendEvent($this->event('evt_invalid'), 't='.time().',v1=invalid')->assertStatus(400);
        $expired = $this->event('evt_expired'); $raw = json_encode($expired, JSON_THROW_ON_ERROR); $past = time() - 600;
        $this->sendEvent($expired, 't='.$past.',v1='.hash_hmac('sha256', $past.'.'.$raw, 'whsec_local'))->assertStatus(400);
        Http::assertNothingSent();
        Http::fakeSequence()->push([], 503)->push($this->listing([$this->subscription()]));
        $this->sendEvent($this->event('evt_retry'))->assertStatus(503);
        $this->assertDatabaseCount('billing_events', 0);
        $this->assertSame('free', $user->fresh()->plan);
        $this->sendEvent($this->event('evt_retry'))->assertOk();
        $this->assertSame('pro', $user->fresh()->plan);
        $this->assertDatabaseCount('billing_events', 1);
    }

    public function test_nonterminal_remote_subscriptions_block_duplicate_checkout_and_account_deletion(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user);
        foreach (['paused', 'incomplete', 'unpaid', 'future_nonterminal_status'] as $status) {
            Http::fake(fn () => Http::response($this->listing([$this->subscription($status)])));
            $this->actingAs($user)->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertStatus(409);
            $this->deleteJson('/account', ['password' => 'long-test-password'])->assertStatus(422)->assertJsonValidationErrors('billing');
        }
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_pending_or_just_completed_checkout_blocks_deletion_before_subscription_webhook(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user); $checkoutStatus = 'open';
        Http::fake(function ($request) use (&$checkoutStatus) {
            return Http::response($this->listing(str_contains($request->url(), '/subscriptions') ? [] : [$this->checkoutSession($checkoutStatus)]));
        });
        $this->actingAs($user)->deleteJson('/account', ['password' => 'long-test-password'])->assertStatus(422)->assertJsonValidationErrors('billing');
        $checkoutStatus = 'complete';
        $this->deleteJson('/account', ['password' => 'long-test-password'])->assertStatus(422)->assertJsonValidationErrors('billing');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_ambiguous_checkout_failure_retries_the_identical_idempotency_request_and_blocks_deletion(): void
    {
        $this->configure(); $user = $this->writer();
        $this->account($user, ['checkout_session_id' => 'cs_old', 'checkout_status' => 'expired', 'checkout_plan' => 'pro']);
        $keys = []; $payloads = [];
        Http::fake(function ($request) use (&$keys, &$payloads) {
            if (str_ends_with($request->url(), '/checkout/sessions/cs_old')) { return Http::response($this->checkoutSession('expired', 'cs_old')); }
            if ($request->method() === 'GET') { return Http::response($this->listing([])); }
            $keys[] = $request->header('Idempotency-Key')[0] ?? ''; $payloads[] = $request->data();
            if (count($keys) === 1) { throw new ConnectionException('Simulated ambiguous network failure.'); }
            return Http::response($this->checkoutSession());
        });
        $this->actingAs($user)->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertStatus(503);
        $this->assertNotNull(DB::table('billing_accounts')->value('checkout_request_key'));
        $this->deleteJson('/account', ['password' => 'long-test-password'])->assertStatus(422)->assertJsonValidationErrors('billing');
        $this->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertOk();
        $this->assertCount(2, $keys); $this->assertSame($keys[0], $keys[1]); $this->assertSame($payloads[0], $payloads[1]);
    }

    public function test_management_portal_remains_available_when_new_checkout_is_disabled(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user, ['status' => 'active']);
        config(['billing.enabled' => false]);
        Http::fake(['https://api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/local'])]);
        $this->actingAs($user)->getJson('/billing/status')->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.can_manage', true);
        $this->postJson('/billing/checkout', ['plan' => 'pro', 'acknowledge_checkout' => true])->assertStatus(503);
        $this->postJson('/billing/portal')->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/local');
        Http::assertSentCount(1);
    }

    public function test_verified_terminal_subscriptions_and_expired_checkout_allow_account_deletion(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user, ['status' => 'active']);
        Http::fake(fn ($request) => Http::response($this->listing(str_contains($request->url(), '/subscriptions')
            ? [$this->subscription('canceled')] : [$this->checkoutSession('expired')])));
        $this->actingAs($user)->deleteJson('/account', ['password' => 'long-test-password'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('billing_accounts', 0);
    }

    public function test_account_deletion_is_blocked_if_remote_billing_cannot_be_verified(): void
    {
        config(['billing.secret_key' => null]);
        $user = $this->writer(); $this->account($user, ['status' => 'active']);
        $this->actingAs($user)->deleteJson('/account', ['password' => 'long-test-password'])->assertStatus(422)->assertJsonValidationErrors('billing');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Http::assertNothingSent();
    }

    public function test_hosted_portal_url_rejects_wrong_hosts_credentials_and_nonstandard_ports(): void
    {
        $this->configure(); $user = $this->writer(); $this->account($user);
        foreach (['https://billing.stripe.com.attacker.test/', 'https://attacker@billing.stripe.com/', 'https://billing.stripe.com:8443/'] as $url) {
            Http::fake(['*' => Http::response(['url' => $url])]);
            $this->actingAs($user)->postJson('/billing/portal')->assertStatus(503);
        }
    }
}
