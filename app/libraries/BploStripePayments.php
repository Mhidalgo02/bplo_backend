<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/BploManualPayments.php';

// Stripe Checkout (test mode) for assessments whose method is "Stripe".
// A completed checkout is recorded as an administrator-verified submission,
// so the existing Paid status and release checks apply unchanged.
class BploStripePayments
{
    private $db;
    public function __construct($db) { $this->db = $db; }

    public static function installed($db)
    {
        $setting = $db->table('bplo_settings')->where('setting_key', 'stripe_payments_schema')->get();
        return $setting && (json_decode($setting['setting_json'], true)['version'] ?? 0) === 1;
    }

    private function client()
    {
        $key = trim((string)getenv('STRIPE_SECRET_KEY'));
        if (!str_starts_with($key, 'sk_test_')) throw new DomainException('Stripe test payments need a backend sk_test_ key.', 503);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new DomainException('Install backend Composer dependencies for Stripe test payments.', 503);
        require_once $autoload;
        return new \Stripe\StripeClient($key);
    }

    private function frontendUrl()
    {
        $origin = rtrim((string)getenv('FRONTEND_URL'), '/');
        if (!filter_var($origin, FILTER_VALIDATE_URL) || !in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new DomainException('Configure FRONTEND_URL for Stripe returns.', 503);
        }
        return $origin;
    }

    private function fee($applicationId)
    {
        if (!BploManualPayments::installed($this->db)) {
            throw new DomainException('Manual payments need database setup. Run php scripts/setup_manual_payments.php from backend.', 503);
        }
        $fee = $this->db->table('bplo_payment_assessments')->where('application_id', $applicationId)->get();
        if (!$fee || (int)$fee['amount_cents'] <= 0) throw new DomainException('No payable Stripe fee has been assessed.', 409);
        if ($fee['method'] !== 'Stripe') throw new DomainException('This assessment is paid manually, not through Stripe.', 409);
        return $fee;
    }

    public function checkout($app, $actor)
    {
        if ($actor['role'] !== 'applicant') throw new DomainException('Only the applicant can pay online.', 403);
        if ((string)$app['account_id'] !== (string)$actor['id']) throw new DomainException('Not permitted.', 403);
        if (in_array($app['status'], ['Released', 'Rejected'], true)) throw new DomainException('Payments are closed for released or rejected applications.', 409);
        if (!BploStripePayments::installed($this->db)) {
            throw new DomainException('Stripe payments need database setup. Run php scripts/setup_stripe_payments.php from backend.', 503);
        }
        $fee = $this->fee($app['id']);
        $state = (new BploManualPayments($this->db))->state($app['id']);
        if (!in_array($state['paymentStatus'], ['Unpaid', 'Rejected'], true)) {
            throw new DomainException('A payment is already awaiting verification or has been paid.', 409);
        }

        $this->db->transaction();
        try {
            $locked = $this->db->raw('SELECT * FROM bplo_applications WHERE id = ? FOR UPDATE', [$app['id']])->fetch();
            if (!$locked || in_array($locked['status'], ['Released', 'Rejected'], true)) throw new DomainException('Payments are closed for released or rejected applications.', 409);

            $attempt = $this->db->table('bplo_stripe_payments')->where('application_id', $app['id'])
                ->in('state', ['creating', 'open', 'reconciliation'])->order_by('id', 'DESC')->get();
            if (!$attempt) {
                $id = $this->db->table('bplo_stripe_payments')->insert([
                    'application_id' => $app['id'], 'attempt_key' => 'bplo-stripe-' . bin2hex(random_bytes(20)),
                    'amount_cents' => (int)$fee['amount_cents'], 'currency' => 'php', 'state' => 'creating',
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $attempt = $this->db->table('bplo_stripe_payments')->where('id', $id)->get();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }

        $client = $this->client();
        $origin = $this->frontendUrl();
        try {
            if ($attempt['session_id']) {
                $session = $client->checkout->sessions->retrieve($attempt['session_id'])->toArray();
                if (($session['status'] ?? '') === 'expired') {
                    $this->db->table('bplo_stripe_payments')->where('id', $attempt['id'])->update(['state' => 'expired', 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    $attempt = null;
                }
            }
            if (!$attempt || !$attempt['session_id']) {
                if (!$attempt) {
                    // Previous attempt expired; start a fresh one below.
                    $id = $this->db->table('bplo_stripe_payments')->insert([
                        'application_id' => $app['id'], 'attempt_key' => 'bplo-stripe-' . bin2hex(random_bytes(20)),
                        'amount_cents' => (int)$fee['amount_cents'], 'currency' => 'php', 'state' => 'creating',
                        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $attempt = $this->db->table('bplo_stripe_payments')->where('id', $id)->get();
                }
                $session = $client->checkout->sessions->create([
                    'mode' => 'payment',
                    'client_reference_id' => $app['reference_number'],
                    'metadata' => ['application_id' => (string)$app['id'], 'attempt_id' => (string)$attempt['id']],
                    'line_items' => [['quantity' => 1, 'price_data' => [
                        'currency' => 'php', 'unit_amount' => (int)$attempt['amount_cents'],
                        'product_data' => ['name' => 'BPLO permit fee - ' . $app['reference_number']],
                    ]]],
                    'success_url' => $origin . '/?payment=return',
                    'cancel_url' => $origin . '/?payment=cancelled',
                ], ['idempotency_key' => $attempt['attempt_key']])->toArray();
                if (($session['livemode'] ?? true) !== false) throw new DomainException('Live Stripe sessions are disabled.', 409);
                $this->db->table('bplo_stripe_payments')->where('id', $attempt['id'])->update([
                    'session_id' => $session['id'], 'checkout_url' => $session['url'] ?? null,
                    'state' => 'open', 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            return ['url' => $session['url'] ?? $attempt['checkout_url'], 'reference' => $app['reference_number']];
        } catch (DomainException $e) {
            $this->db->table('bplo_stripe_payments')->where('id', $attempt['id'])->update(['state' => 'reconciliation', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            throw $e;
        } catch (Throwable $e) {
            $this->db->table('bplo_stripe_payments')->where('id', $attempt['id'])->update(['state' => 'reconciliation', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            throw new DomainException('Stripe checkout could not be started. Check the backend configuration and retry.', 503);
        }
    }

    public function webhook($raw, $signature)
    {
        $this->client();
        $secret = (string)getenv('STRIPE_WEBHOOK_SECRET');
        if (!str_starts_with($secret, 'whsec_')) throw new DomainException('Configure the Stripe test webhook signing secret.', 503);
        if (!BploStripePayments::installed($this->db)) throw new DomainException('Stripe payments are not set up.', 503);
        try {
            $event = \Stripe\Webhook::constructEvent($raw, $signature, $secret);
        } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
            throw new DomainException('Invalid Stripe webhook signature or body.', 400);
        }
        if ($event->livemode !== false) throw new DomainException('Live payment events are disabled.', 400);
        $event = $event->toArray();
        $session = $event['data']['object'] ?? [];
        if (($event['type'] ?? '') === 'checkout.session.expired') {
            $attempt = $this->attemptFor($session);
            if ($attempt) $this->db->table('bplo_stripe_payments')->where('id', $attempt['id'])->where('state', '!=', 'paid')->update(['state' => 'expired', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return 'expired';
        }
        if (($event['type'] ?? '') !== 'checkout.session.completed') return 'ignored';
        if (($session['payment_status'] ?? '') !== 'paid' || ($session['status'] ?? '') !== 'complete') return 'unpaid';
        $attempt = $this->attemptFor($session);
        if (!$attempt) return 'unknown_session';
        $this->markPaid($attempt, $session);
        return 'paid';
    }

    private function attemptFor(array $session)
    {
        $attempt = null;
        if (!empty($session['id'])) $attempt = $this->db->table('bplo_stripe_payments')->where('session_id', $session['id'])->get();
        if (!$attempt && ctype_digit((string)($session['metadata']['attempt_id'] ?? ''))) {
            $attempt = $this->db->table('bplo_stripe_payments')->where('id', (int)$session['metadata']['attempt_id'])->get();
        }
        return $attempt;
    }

    private function markPaid(array $attempt, array $session)
    {
        $referenceKey = hash('sha256', 'stripe:' . ($session['id'] ?? $attempt['session_id']));
        $this->db->transaction();
        try {
            $app = $this->db->raw('SELECT * FROM bplo_applications WHERE id = ? FOR UPDATE', [$attempt['application_id']])->fetch();
            if (!$app) throw new DomainException('Application not found.', 404);
            $existing = $this->db->table('bplo_payment_submissions')->where('reference_key', $referenceKey)->get();
            $fee = $this->db->table('bplo_payment_assessments')->where('application_id', $app['id'])->get();
            if (!$fee || $fee['method'] !== 'Stripe' || (int)$fee['amount_cents'] !== (int)$attempt['amount_cents']) {
                throw new DomainException('Assessment changed since checkout started; payment needs reconciliation.', 409);
            }
            if ((int)($session['amount_total'] ?? -1) !== (int)$attempt['amount_cents'] || ($session['currency'] ?? '') !== 'php') {
                throw new DomainException('Stripe amount or currency does not match the assessment; payment needs reconciliation.', 409);
            }
            if (!$existing) {
                $paidOn = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
                $this->db->table('bplo_payment_submissions')->insert([
                    'application_id' => $app['id'], 'amount_cents' => (int)$attempt['amount_cents'], 'method' => 'Stripe',
                    'payment_reference' => (string)($session['id'] ?? $attempt['session_id']), 'reference_key' => $referenceKey,
                    'paid_on' => $paidOn, 'file_name' => 'Stripe checkout receipt',
                    'storage_name' => 'stripe-' . substr(hash('sha256', (string)$session['id']), 0, 40),
                    'mime_type' => 'application/vnd.stripe.checkout', 'file_size' => 0,
                    'review_status' => 'Verified', 'review_remarks' => 'Paid online through Stripe Checkout (test mode).',
                    'reviewed_at' => gmdate('Y-m-d H:i:s'), 'submitted_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->db->table('bplo_audit')->insert(['account_id' => null, 'actor' => 'Stripe',
                    'action' => 'Payment verified', 'reference' => $app['reference_number'],
                    'details' => 'Stripe checkout ' . ($session['id'] ?? '') . ' confirmed PHP ' . number_format((int)$attempt['amount_cents'] / 100, 2, '.', '') . '.']);
                $this->db->table('bplo_notifications')->insert(['account_id' => $app['account_id'],
                    'title' => 'Payment received',
                    'message' => 'Your Stripe payment of PHP ' . number_format((int)$attempt['amount_cents'] / 100, 2, '.', '') . ' for ' . $app['reference_number'] . ' was confirmed. Payment has been verified.',
                    'reference' => $app['reference_number']]);
            }
            $this->db->table('bplo_stripe_payments')->where('id', $attempt['id'])->update(['state' => 'paid', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $this->db->commit();
            return 'paid';
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
