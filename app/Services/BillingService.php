<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Optional hosted Stripe checkout. No payment details are collected by HumixAi. */
class BillingService
{
    public function configured(): bool
    {
        return (bool) config('billing.enabled') && $this->managementConfigured() && filled(config('billing.webhook_secret'))
            && filled(config('billing.plans.pro.price_id'));
    }

    public function managementConfigured(): bool { return filled(config('billing.secret_key')); }

    public function status(User $user): array
    {
        $account = DB::table('billing_accounts')->where('user_id', $user->id)->first();
        return ['configured' => $this->configured(), 'provider' => 'stripe', 'plan' => $user->plan,
            'subscription_status' => $account?->status ?? 'none', 'can_manage' => $this->managementConfigured() && filled($account?->customer_id),
            'management_configured' => $this->managementConfigured(), 'reconciled_at' => $account?->reconciled_at,
            'pending_checkout' => filled($account?->checkout_request_key) || ($account?->checkout_status === 'open'),
            'plans' => collect(config('billing.plans'))->map(fn ($plan, $id) => ['id' => $id, 'name' => $plan['name'],
                'daily_word_limit' => $plan['daily_word_limit'], 'available' => $this->configured() && filled($plan['price_id'])])->values()->all(),
            'notice' => 'Pricing, currency and recurring terms are shown in Stripe Checkout before you approve a subscription. A redirect alone does not activate a plan.'];
    }

    public function checkout(User $user, string $plan): array
    {
        $this->requireConfiguration();
        $settings = config('billing.plans.'.$plan);
        if (!$settings || empty($settings['price_id'])) { throw new HttpException(422, 'This plan is not available.'); }
        return $this->locked($user, function () use ($user, $plan, $settings) {
            $account = DB::table('billing_accounts')->where('user_id', $user->id)->first();
            if (!$account?->customer_id) {
                $customer = $this->api('POST', '/customers', ['email' => $user->email, 'name' => $user->name,
                    'metadata' => ['humixai_user_id' => (string) $user->id]], 'humix-customer-'.$user->id);
                if (!$this->identifier($customer['id'] ?? null, 'cus_')) { throw new HttpException(503, 'Billing did not return a valid customer.'); }
                DB::table('billing_accounts')->updateOrInsert(['user_id' => $user->id], ['customer_id' => $customer['id'], 'status' => 'none', 'created_at' => now(), 'updated_at' => now()]);
                $account = DB::table('billing_accounts')->where('user_id', $user->id)->first();
            }
            $subscriptions = $this->subscriptions($account->customer_id);
            $this->syncSubscriptions($account, $subscriptions);
            if ($this->nonterminal($subscriptions) !== []) { throw new HttpException(409, 'Manage your existing or pending subscription through the billing portal.'); }
            $open = $this->openSessions($account->customer_id);
            if (count($open) > 1) { throw new HttpException(409, 'Several pending checkouts need attention. Let them expire or contact billing support before starting another.'); }
            if ($open !== []) {
                $session = $open[0];
                $this->validateSession($session, $account->customer_id);
                if ($account->checkout_request_key && ($session['metadata']['humixai_request_key'] ?? null) !== $account->checkout_request_key) {
                    throw new HttpException(409, 'A previous billing request is still unresolved. Do not start another payment.');
                }
                $sessionPlan = $session['metadata']['humixai_plan'] ?? ($session['id'] === $account->checkout_session_id ? $account->checkout_plan : null);
                if ($sessionPlan !== $plan) { throw new HttpException(409, 'An existing checkout must finish or expire before a different checkout can start.'); }
                $this->rememberSession($account, $session, $plan);
                return ['url' => $this->safeUrl($session['url'] ?? null, 'checkout.stripe.com')];
            }
            if ($account->checkout_session_id) {
                $session = $this->api('GET', '/checkout/sessions/'.rawurlencode($account->checkout_session_id), []);
                $this->validateSession($session, $account->customer_id);
                $this->rememberSession($account, $session, $account->checkout_plan, false);
                if ($session['status'] !== 'expired' && !$this->completedTerminalSession($session, $subscriptions)) {
                    throw new HttpException(409, 'Your previous checkout is still being resolved. Do not start a second subscription.');
                }
                $account = DB::table('billing_accounts')->where('id', $account->id)->first();
            }
            if ($account->checkout_request_key) {
                if (!$account->checkout_request_started_at || \Carbon\Carbon::parse($account->checkout_request_started_at)->lt(now()->subHours(23))) {
                    throw new HttpException(409, 'A previous billing request needs administrator reconciliation before another checkout can start.');
                }
                $payload = json_decode($account->checkout_request_payload ?? '', true);
                if (!is_array($payload) || ($payload['metadata']['humixai_plan'] ?? null) !== $plan) {
                    throw new HttpException(409, 'Resolve the previous checkout request before changing plans.');
                }
                $key = $account->checkout_request_key;
            } else {
                $key = 'humix-checkout-'.Str::uuid();
                $payload = ['mode' => 'subscription', 'customer' => $account->customer_id,
                    'client_reference_id' => (string) $user->id, 'line_items' => [['price' => $settings['price_id'], 'quantity' => 1]],
                    'success_url' => url('/?billing=success'), 'cancel_url' => url('/?billing=cancelled'), 'expires_at' => now()->addMinutes(30)->timestamp,
                    'metadata' => ['humixai_user_id' => (string) $user->id, 'humixai_plan' => $plan, 'humixai_request_key' => $key],
                    'subscription_data' => ['metadata' => ['humixai_user_id' => (string) $user->id, 'humixai_plan' => $plan]]];
                // Persist before contacting Stripe so an ambiguous network failure cannot trigger a fresh second purchase.
                DB::table('billing_accounts')->where('id', $account->id)->update(['checkout_request_key' => $key,
                    'checkout_request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'checkout_request_started_at' => now(), 'updated_at' => now()]);
            }
            $session = $this->api('POST', '/checkout/sessions', $payload, $key);
            $this->validateSession($session, $account->customer_id);
            $this->rememberSession($account, $session, $plan);
            if ($session['status'] !== 'open' || $session['expires_at'] <= now()->timestamp) {
                throw new HttpException(409, 'The recovered checkout has already ended. Refresh your billing status before trying again.');
            }
            return ['url' => $this->safeUrl($session['url'] ?? null, 'checkout.stripe.com')];
        });
    }

    public function portal(User $user): array
    {
        if (!$this->managementConfigured()) { throw new HttpException(503, 'Billing management is not configured on this server.'); }
        return $this->locked($user, function () use ($user) {
            $account = DB::table('billing_accounts')->where('user_id', $user->id)->first();
            if (!$account?->customer_id) { throw new HttpException(422, 'No billing customer is linked to this account.'); }
            $result = $this->api('POST', '/billing_portal/sessions', ['customer' => $account->customer_id, 'return_url' => url('/')]);
            return ['url' => $this->safeUrl($result['url'] ?? null, 'billing.stripe.com')];
        });
    }

    public function webhook(string $raw, string $signature): void
    {
        if (!filled(config('billing.webhook_secret'))) { throw new HttpException(503, 'Billing webhook is not configured.'); }
        if (strlen($raw) > 1048576 || strlen($signature) > 2000) { throw new HttpException(400, 'Invalid webhook.'); }
        $parts = []; foreach (explode(',', $signature) as $part) { $pair = explode('=', trim($part), 2); if (count($pair) === 2) { $parts[$pair[0]][] = $pair[1]; } }
        $timestamp = $parts['t'][0] ?? '';
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, config('billing.webhook_secret'));
        $valid = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300;
        $matches = false; foreach ($parts['v1'] ?? [] as $provided) { $matches = hash_equals($expected, $provided) || $matches; }
        if (!$valid || !$matches) { throw new HttpException(400, 'Invalid webhook signature.'); }
        $event = json_decode($raw, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/^evt_[A-Za-z0-9_]{1,100}$/', $event['id']) || !is_string($event['type'] ?? null)) { throw new HttpException(400, 'Invalid webhook event.'); }
        if (DB::table('billing_events')->where('id', $event['id'])->exists()) { return; }
        $object = $event['data']['object'] ?? [];
        $supported = in_array($event['type'], ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'], true);
        if (!$supported || !is_array($object)) { $this->recordEvent($event); return; }
        $customer = $object['customer'] ?? null;
        if (!$this->identifier($customer, 'cus_')) { throw new HttpException(400, 'Invalid subscription customer.'); }
        $account = DB::table('billing_accounts')->where('customer_id', $customer)->first();
        if (!$account) { $this->recordEvent($event); return; }
        $this->customerLock((int) $account->user_id, function () use ($event, $account) {
            if (DB::table('billing_events')->where('id', $event['id'])->exists()) { return; }
            $current = DB::table('billing_accounts')->where('id', $account->id)->first();
            if (!$current) { $this->recordEvent($event); return; }
            // Events only signal that a refresh is required. Their snapshot timestamps do not order state.
            $subscriptions = $this->subscriptions($current->customer_id);
            DB::transaction(function () use ($current, $subscriptions, $event) {
                $this->syncSubscriptions($current, $subscriptions);
                $this->recordEvent($event);
            }, 3);
        });
    }

    private function recordEvent(array $event): void
    {
        DB::table('billing_events')->insertOrIgnore(['id' => $event['id'], 'type' => mb_substr($event['type'], 0, 100), 'created_at' => now()]);
    }

    private function requireConfiguration(): void { if (!$this->configured()) { throw new HttpException(503, 'Billing is not configured on this server.'); } }

    /** Holds the same lock as checkout until the local account is actually deleted. */
    public function deleteAccountSafely(User $user, callable $delete): void
    {
        $this->locked($user, function () use ($user, $delete) {
            $account = DB::table('billing_accounts')->where('user_id', $user->id)->first();
            if (!$account) { $delete(); return; }
            if (!$account->customer_id || !$this->managementConfigured()) {
                $this->deletionBlocked('Billing cannot be verified. Ask the administrator to restore billing management before deleting this account.');
            }
            $subscriptions = $this->subscriptions($account->customer_id);
            $this->syncSubscriptions($account, $subscriptions);
            if ($this->nonterminal($subscriptions) !== []) {
                $this->deletionBlocked('End every active, paused or pending subscription through the billing portal before deleting your account.');
            }
            // Inspect completed sessions too: a payment can finish between the subscription and checkout reads.
            $sessions = $this->listing('/checkout/sessions', ['customer' => $account->customer_id]);
            foreach ($sessions as $session) {
                $this->validateSession($session, $account->customer_id);
                if ($session['status'] === 'open') {
                    $this->deletionBlocked('A checkout is still open. Let it expire or resolve it before deleting your account; local deletion would not prevent a later payment.');
                }
                if ($session['status'] === 'complete' && !$this->completedTerminalSession($session, $subscriptions)) {
                    $this->deletionBlocked('A completed checkout is still being reconciled. Resolve billing before deleting your account.');
                }
            }
            if ($account->checkout_request_key) {
                $this->deletionBlocked('A previous checkout request has not been reconciled. Resume checkout or contact billing support before deleting this account.');
            }
            if ($account->checkout_session_id) {
                $session = $this->api('GET', '/checkout/sessions/'.rawurlencode($account->checkout_session_id), []);
                $this->validateSession($session, $account->customer_id);
                if ($session['status'] !== 'expired' && !$this->completedTerminalSession($session, $subscriptions)) {
                    $this->deletionBlocked('The most recent checkout is still being resolved. Try again after billing has finished reconciling.');
                }
            }
            $delete();
        });
    }

    private function subscriptions(string $customer): array
    {
        $data = $this->listing('/subscriptions', ['customer' => $customer, 'status' => 'all']);
        foreach ($data as $subscription) {
            if (!$this->identifier($subscription['id'] ?? null, 'sub_') || ($subscription['customer'] ?? null) !== $customer
                || !is_string($subscription['status'] ?? null) || $subscription['status'] === '' || strlen($subscription['status']) > 50
                || !is_array($subscription['items']['data'] ?? null) || !array_is_list($subscription['items']['data'])
                || ($subscription['items']['has_more'] ?? false) !== false) { throw new HttpException(503, 'Stripe subscription state could not be verified.'); }
        }
        return $data;
    }

    private function openSessions(string $customer): array
    {
        $sessions = $this->listing('/checkout/sessions', ['customer' => $customer, 'status' => 'open']);
        foreach ($sessions as $session) {
            $this->validateSession($session, $customer);
            if ($session['status'] !== 'open') { throw new HttpException(503, 'Stripe checkout state could not be verified.'); }
        }
        return $sessions;
    }

    private function listing(string $path, array $parameters): array
    {
        $parameters['limit'] = 100; $items = []; $cursor = null;
        for ($page = 0; $page < 5; $page++) {
            if ($cursor !== null) { $parameters['starting_after'] = $cursor; }
            $result = $this->api('GET', $path, $parameters);
            if (!is_array($result['data'] ?? null) || !array_is_list($result['data']) || !is_bool($result['has_more'] ?? null)
                || count($result['data']) > 100) { throw new HttpException(503, 'Stripe returned an incomplete account listing.'); }
            foreach ($result['data'] as $item) { if (!is_array($item)) { throw new HttpException(503, 'Stripe returned invalid account state.'); } }
            $items = array_merge($items, $result['data']);
            if (!$result['has_more']) { return $items; }
            $next = end($result['data'])['id'] ?? null;
            if (!is_string($next) || $next === $cursor) { throw new HttpException(503, 'Stripe account pagination could not be verified.'); }
            $cursor = $next;
        }
        throw new HttpException(503, 'This billing account needs administrator reconciliation before it can be changed.');
    }

    private function nonterminal(array $subscriptions): array
    {
        return array_values(array_filter($subscriptions, fn ($subscription) => !in_array($subscription['status'], ['canceled', 'incomplete_expired'], true)));
    }

    private function syncSubscriptions(object $account, array $subscriptions): void
    {
        $open = $this->nonterminal($subscriptions); $best = null; $bestLimit = -1; $bestPlan = null; $bestPrice = null;
        foreach ($open as $subscription) {
            if (!in_array($subscription['status'], ['active', 'trialing'], true)) { continue; }
            foreach ($subscription['items']['data'] as $item) {
                $price = $item['price']['id'] ?? null;
                foreach ((array) config('billing.plans', []) as $planId => $plan) {
                    if (filled($plan['price_id'] ?? null) && $price === $plan['price_id'] && (int) $plan['daily_word_limit'] > $bestLimit) {
                        $best = $subscription; $bestPlan = $planId; $bestPrice = $price; $bestLimit = (int) $plan['daily_word_limit'];
                    }
                }
            }
        }
        $selected = $best ?? ($open[0] ?? ($subscriptions[0] ?? null));
        $status = count($open) > 1 ? 'multiple' : ($selected['status'] ?? 'none');
        $state = array_map(fn ($item) => ['id' => $item['id'], 'status' => $item['status']], $subscriptions);
        DB::transaction(function () use ($account, $selected, $status, $bestPrice, $state, $bestPlan, $bestLimit) {
            DB::table('billing_accounts')->where('id', $account->id)->update(['subscription_id' => $selected['id'] ?? null,
                'status' => $status, 'price_id' => $bestPrice, 'remote_subscriptions' => json_encode($state, JSON_THROW_ON_ERROR),
                'reconciled_at' => now(), 'updated_at' => now()]);
            User::whereKey($account->user_id)->update(['plan' => $bestPlan ?? 'free', 'daily_word_limit' => $bestPlan !== null ? $bestLimit : null]);
        }, 3);
    }

    private function validateSession(array $session, string $customer): void
    {
        if (!$this->identifier($session['id'] ?? null, 'cs_') || ($session['customer'] ?? null) !== $customer
            || ($session['mode'] ?? null) !== 'subscription' || !in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true)
            || !is_int($session['expires_at'] ?? null)) { throw new HttpException(503, 'Stripe checkout state could not be verified.'); }
        if ($session['status'] === 'open') { $this->safeUrl($session['url'] ?? null, 'checkout.stripe.com'); }
    }

    private function rememberSession(object $account, array $session, ?string $plan, bool $clearRequest = true): void
    {
        $values = ['checkout_session_id' => $session['id'],
            'checkout_status' => $session['status'], 'checkout_plan' => $plan,
            'checkout_url' => $session['status'] === 'open' ? $this->safeUrl($session['url'] ?? null, 'checkout.stripe.com') : null,
            'checkout_expires_at' => \Carbon\Carbon::createFromTimestamp($session['expires_at']),
            'updated_at' => now()];
        if ($clearRequest) { $values += ['checkout_request_key' => null, 'checkout_request_payload' => null, 'checkout_request_started_at' => null]; }
        DB::table('billing_accounts')->where('id', $account->id)->update($values);
    }

    private function completedTerminalSession(array $session, array $subscriptions): bool
    {
        if ($session['status'] !== 'complete' || !$this->identifier($session['subscription'] ?? null, 'sub_')) { return false; }
        foreach ($subscriptions as $subscription) {
            if ($subscription['id'] === $session['subscription']) { return in_array($subscription['status'], ['canceled', 'incomplete_expired'], true); }
        }
        return false;
    }

    private function locked(User $user, callable $callback): mixed
    {
        return $this->customerLock($user->id, function () use ($user, $callback) {
            if (!User::whereKey($user->id)->exists()) { throw new HttpException(401, 'This account is no longer available.'); }
            return $callback();
        });
    }

    private function customerLock(int $userId, callable $callback): mixed
    {
        try { return Cache::lock('humix-billing:'.$userId, 300)->block(5, $callback); }
        catch (\Illuminate\Contracts\Cache\LockTimeoutException) { throw new HttpException(409, 'Another billing operation is in progress. Try again shortly.'); }
    }

    private function identifier(mixed $id, string $prefix): bool
    {
        return is_string($id) && preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9_]{1,200}$/D', $id) === 1;
    }

    private function deletionBlocked(string $message): never { throw ValidationException::withMessages(['billing' => [$message]]); }

    private function api(string $method, string $path, array $body, ?string $idempotency = null): array
    {
        try {
            if (!$this->managementConfigured()) { throw new \RuntimeException(); }
            $request = Http::asForm()->acceptJson()->withBasicAuth(config('billing.secret_key'), '')->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
            if (filled(config('billing.api_version'))) { $request = $request->withHeaders(['Stripe-Version' => config('billing.api_version')]); }
            if ($idempotency) { $request = $request->withHeaders(['Idempotency-Key' => $idempotency]); }
            $response = $method === 'GET' ? $request->get('https://api.stripe.com/v1'.$path, $body) : $request->post('https://api.stripe.com/v1'.$path, $body);
            if (!$response->successful() || strlen($response->body()) > 4 * 1024 * 1024 || !is_array($response->json())) { throw new \RuntimeException(); }
            return $response->json();
        } catch (\Throwable) { throw new HttpException(503, 'Billing could not be verified. Do not start another payment; retry the same request or contact billing support.'); }
    }

    private function safeUrl(mixed $url, string $host): string
    {
        if (!is_string($url) || strlen($url) > 8192 || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $host
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || !in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) { throw new HttpException(503, 'Billing returned an invalid redirect.'); }
        return $url;
    }
}
