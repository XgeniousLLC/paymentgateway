<?php
// RazorPay.php - Updated with Dynamic Plans

namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Facades\Http;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Razorpay\Api\Api;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\IndianCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class RazorPay extends PaymentGatewayBase
{
    use PaymentEnvironment, CurrencySupport, IndianCurrencySupport;

    protected $api_key;
    protected $api_secret;
    protected $webhook_secret;

    public function setApiKey($api_key) {
        $this->api_key = $api_key;
        return $this;
    }

    private function getApiKey() {
        return $this->api_key;
    }

    public function setApiSecret($api_secret) {
        $this->api_secret = $api_secret;
        return $this;
    }

    private function getApiSecret() {
        return $this->api_secret;
    }

    public function setWebhookSecret($webhook_secret) {
        $this->webhook_secret = $webhook_secret;
        return $this;
    }

    private function getWebhookSecret() {
        return $this->webhook_secret;
    }

    /**
     * ========================================
     * DYNAMIC PLAN CREATION/RETRIEVAL
     * ========================================
     */

    /**
     * Create Subscription Plan in Razorpay
     */
    public function create_subscription_plan($plan_data)
    {
        try {
            $api = new Api($this->getApiKey(), $this->getApiSecret());

            $period = $plan_data['period'] ?? 'monthly';

            // Razorpay API format for creating plans - minimal required fields
            $create_data = [
                'period' => $period,
                'interval' => 1,
                'item' => [
                    'name' => $plan_data['item_name'],
                    'description' => $plan_data['description'] ?? 'Subscription Plan',
                    'amount' => (int)($plan_data['amount'] * 100), // Convert to paise
                    'currency' => 'INR'
                ]
            ];

            // Add notes if provided
            if (!empty($plan_data['app_plan_id']) || !empty($plan_data['plan_type'])) {
                $create_data['notes'] = [
                    'app_plan_id' => $plan_data['app_plan_id'] ?? null,
                    'plan_type' => $plan_data['plan_type'] ?? null,
                ];
            }

            \Illuminate\Support\Facades\Log::info('Creating Razorpay plan with data:', $create_data);

            $plan = $api->plan->create($create_data);

            \Illuminate\Support\Facades\Log::info('Razorpay plan created successfully', [
                'plan_id' => $plan->id,
                'period' => $period
            ]);

            return [
                'status' => 'success',
                'plan_id' => $plan->id,
                'plan_data' => $plan
            ];

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create subscription plan', [
                'error' => $e->getMessage(),
                'plan_data' => $plan_data,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get or Create a Razorpay Plan dynamically
     */
    public function getOrCreatePlan($plan_config)
    {
        try {
            $api = new Api($this->getApiKey(), $this->getApiSecret());

            // Check if plan already exists (from DB or cache)
            $existing_plan_id = $this->getPlanFromDatabase($plan_config);

            if ($existing_plan_id) {
                return [
                    'status' => 'success',
                    'plan_id' => $existing_plan_id,
                    'created' => false
                ];
            }

            // Create new plan in Razorpay
            $period = $this->getPeriodFromPlanType($plan_config['type']);

            if (!$period) {
                return [
                    'status' => 'failed',
                    'message' => 'Lifetime plans do not support subscriptions'
                ];
            }

            $create_plan_data = [
                'period' => $period,
                'interval' => 1,
                'item_name' => $plan_config['title'],
                'amount' => $plan_config['price'],
                'description' => $plan_config['package_description'] ?? 'Subscription Plan',
                'app_plan_id' => $plan_config['id'],
                'plan_type' => $plan_config['type']
            ];

            // Use the create_subscription_plan method
            $result = $this->create_subscription_plan($create_plan_data);

            if ($result['status'] !== 'success') {
                return $result;
            }

            // Store in database
            $this->savePlanToDatabase($plan_config['id'], $result['plan_id']);

            return [
                'status' => 'success',
                'plan_id' => $result['plan_id'],
                'created' => true,
                'razorpay_plan' => $result['plan_data']
            ];

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create Razorpay plan', [
                'error' => $e->getMessage(),
                'plan_config' => $plan_config
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get plan period based on plan type
     */
    private function getPeriodFromPlanType($type)
    {
        return match($type) {
            0 => 'monthly',
            1 => 'yearly',
            2 => null,
            default => 'monthly'
        };
    }

    /**
     * Get plan from database
     */
    private function getPlanFromDatabase($plan_config)
    {
        // Check if PricePlan model has razorpay_plan_id
        if (isset($plan_config['razorpay_plan_id']) && $plan_config['razorpay_plan_id']) {
            return $plan_config['razorpay_plan_id'];
        }
        return null;
    }

    /**
     * Save plan to database
     */
    private function savePlanToDatabase($app_plan_id, $razorpay_plan_id)
    {
        try {
            // Update PricePlan model
            \App\Models\PricePlan::where('id', $app_plan_id)
                ->update([
                    'razorpay_plan_id' => $razorpay_plan_id,
                    'razorpay_synced_at' => now()
                ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to save plan to database', ['error' => $e->getMessage()]);
        }
    }

    /**
     * ========================================
     * DYNAMIC SUBSCRIPTION CREATION
     * ========================================
     */

    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->is_decimal($amount) ? $amount : $amount;
        }
        return $this->is_decimal($this->get_amount_in_inr($amount))
            ? $this->get_amount_in_inr($amount)
            : $this->get_amount_in_inr($amount);
    }

    /**
     * Main charge_customer method with dynamic subscription support
     */
    public function charge_customer($args)//
    {
        $amount_in_inr = $this->charge_amount($args['amount']);

        $razorpay_data['api_key'] = $this->getApiKey();
        $razorpay_data['currency'] = $this->charge_currency();
        $razorpay_data['title'] = $args['title'];
        $razorpay_data['description'] = $args['description'];
        $razorpay_data['route'] = $args['ipn_url'];
        $razorpay_data['order_id'] = $args['order_id'];

        $razorpay_data['notes'] = $args['notes'] ?? [
            'payment_log_id' => $args['order_id'],
            'order_id' => $args['order_id']
        ];
        // payment ata dia nicche na? atai to payment ar file,?haa to atar request ar body te to kono cancel url dewa nai..
        session()->put('razorpay_last_order_id',$args['order_id']);

        // Check if subscription is requested (via $args['is_subscription'])
        if (isset($args['is_subscription']) && $args['is_subscription'] === true) {
            return $this->createDynamicSubscription($args, $razorpay_data);
        }

        // Standard one-time payment
        return $this->createOneTimePayment($args, $razorpay_data);
    }

    /**
     * Create Dynamic Subscription
     */
    private function createDynamicSubscription($args, &$razorpay_data)
    {
        try {
            $api = new Api($this->getApiKey(), $this->getApiSecret());

            // Get or create plan
            $plan_result = $this->getOrCreatePlan($args['plan_config']);

            if ($plan_result['status'] !== 'success') {
                abort(500, 'Failed to create subscription plan: ' . $plan_result['message']);
            }

            $plan_id = $plan_result['plan_id'];

            // Determine total count (number of billing cycles)
            $total_count = $this->getTotalCountFromPlan($args['plan_config']['type']);

            // Create subscription
            $subscription = $api->subscription->create([
                'plan_id' => $plan_id,
                'total_count' => $total_count,
                'quantity' => 1,
                'customer_notify' => 1,
                'notes' => [
                    'order_id' => $args['order_id'],
                    'payment_log_id' => $args['order_id'],
                    'plan_id' => $args['plan_config']['id'],
                    'plan_type' => $args['plan_config']['type']
                ]
            ]);

            $razorpay_data['subscription_id'] = $subscription->id;
            $razorpay_data['is_subscription'] = true;
            $razorpay_data['price'] = $args['amount'];
            $razorpay_data['plan_id'] = $plan_id;

            \Illuminate\Support\Facades\Log::info('Dynamic subscription created', [
                'subscription_id' => $subscription->id,
                'plan_id' => $plan_id,
                'order_id' => $args['order_id']
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Subscription creation failed', ['error' => $e->getMessage()]);
            abort(500, 'Subscription Error: ' . $e->getMessage());
        }

        return view('paymentgateway::razorpay')->with('razorpay_data', $razorpay_data);
    }

    /**
     * Create One-time Payment
     */
    private function createOneTimePayment($args, &$razorpay_data)
    {
        $order_id = random_int(12345, 99999) . $args['order_id'] . random_int(12345, 99999);

        $razorpay_data['price'] = $args['amount'];
        $razorpay_data['order_id'] = $order_id;
        $razorpay_data['is_subscription'] = false;

        session()->put('razorpay_last_order_id', $order_id);

        return view('paymentgateway::razorpay')->with('razorpay_data', $razorpay_data);
    }

    /**
     * Get total count for subscription cycles
     */
    private function getTotalCountFromPlan($plan_type)
    {
        return match($plan_type) {
            0 => 120, // Monthly: 10 years
            1 => 10,  // Yearly: 10 years
            default => 120
        };
    }

    /**
     * IPN Response handling
     */

// RazorPay.php - Updated ipn_response method

    public function ipn_response($args = [])
    {
        $request = request();
        abort_if(is_null($this->getApiKey()), 405, 'razorpay api key is missing');
        abort_if(is_null($this->getApiSecret()), 405, 'razorpay api secret is missing');

        // ========================================
        // Handle Subscription Callback
        // ========================================
        // Check if subscription_id is present AND not empty (recurring enabled)
        if ($request->filled('razorpay_subscription_id')) {
            $subscription_id = $request->razorpay_subscription_id;
            $payment_id = $request->razorpay_payment_id;
            $signature = $request->razorpay_signature;

            // Verify signature exists
            if (is_null($signature) || is_null($payment_id) || is_null($subscription_id)) {
                \Illuminate\Support\Facades\Log::warning('Missing required subscription fields', [
                    'has_subscription_id' => !is_null($subscription_id),
                    'has_payment_id' => !is_null($payment_id),
                    'has_signature' => !is_null($signature)
                ]);
                return ['status' => 'failed'];
            }

            // Generate expected signature
            $expected_signature = hash_hmac('sha256', $payment_id . '|' . $subscription_id, $this->getApiSecret());

            // Verify signature safely
            if (!hash_equals($expected_signature, $signature)) {
                \Illuminate\Support\Facades\Log::warning('Subscription signature mismatch', [
                    'subscription_id' => $subscription_id,
                    'payment_id' => $payment_id
                ]);
                return ['status' => 'failed'];
            }

            $order_id = request()->order_id;

            \Illuminate\Support\Facades\Log::info('Subscription payment verified successfully', [
                'subscription_id' => $subscription_id,
                'payment_id' => $payment_id,
                'order_id' => $order_id
            ]);

            return $this->verified_data([
                'status' => 'complete',
                'order_id' => $order_id,
                'payment_amount' => 0,
                'transaction_id' => $payment_id,
                'captured' => true,
                'razorpay_subscription_id' => $subscription_id // Pass subscription ID to IPN handler
            ]);
        }

        // ========================================
        // Handle Standard Payment Callback
        // ========================================
        if (!$request->has('razorpay_payment_id')) {
            \Illuminate\Support\Facades\Log::warning('No payment ID in Razorpay callback');
            return ['status' => 'failed'];
        }

        $razorpay_payment_id = request()->razorpay_payment_id;
        $order_id = request()->order_id;

        // Remove padding from order_id if present
        if (\strlen($order_id) > 10) {
            $order_id = \substr($order_id, 5, -5);
        }

        // Check if webhook already processed this payment
        // This handles race conditions where webhook completes before IPN callback
        try {
            $payment_log = \App\Models\PaymentLogs::find($order_id);
            if ($payment_log && $payment_log->payment_status === 'complete' && $payment_log->transaction_id === $razorpay_payment_id) {
                \Illuminate\Support\Facades\Log::info('Payment already completed by webhook, skipping API verification', [
                    'payment_id' => $razorpay_payment_id,
                    'order_id' => $order_id,
                    'payment_status' => $payment_log->payment_status
                ]);

                return $this->verified_data([
                    'status' => 'complete',
                    'order_id' => $order_id,
                    'payment_amount' => $payment_log->package_price * 100,
                    'transaction_id' => $razorpay_payment_id,
                    'captured' => true
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to check payment log, proceeding with API verification', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
        }

        // Verify payment with Razorpay API
        $response = Http::withBasicAuth(
            $this->getApiKey(),
            $this->getApiSecret()
        )->timeout(10)->get($this->baseApi() . 'payments/' . $razorpay_payment_id);

        if ($response->ok()) {
            $res_object = $response->object();
            $amount = $res_object->amount;
            $captured = false;
            $final_status = $res_object->status; // Track the final status after capture attempt

            // Check if payment needs to be captured
            if ($res_object->status != 'captured') {
                $capture_response = Http::withBasicAuth($this->getApiKey(), $this->getApiSecret())
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->timeout(10)
                    ->post("https://api.razorpay.com/v1/payments/{$razorpay_payment_id}/capture", [
                        'amount' => $res_object->amount,
                        'currency' => 'INR'
                    ]);

                if ($capture_response->ok()) {
                    $cap_object = $capture_response->object();
                    if ($cap_object->status == 'captured') {
                        $captured = true;
                        $final_status = 'captured'; // Update final status to captured
                    }
                }
            } else {
                $captured = true;
                $final_status = 'captured';
            }

            // Verify payment status - check both original and final status
            // This handles race conditions where payment is authorized but not yet captured
            if (in_array($res_object->status, ['paid', 'authorized', 'captured']) ||
                in_array($final_status, ['paid', 'authorized', 'captured'])) {

                \Illuminate\Support\Facades\Log::info('Standard payment verified successfully', [
                    'payment_id' => $razorpay_payment_id,
                    'original_status' => $res_object->status,
                    'final_status' => $final_status,
                    'captured' => $captured,
                    'order_id' => $order_id
                ]);

                return $this->verified_data([
                    'status' => 'complete',
                    'order_id' => $order_id,
                    'payment_amount' => $amount,
                    'transaction_id' => $razorpay_payment_id,
                    'captured' => $captured
                ]);
            }
        }

        \Illuminate\Support\Facades\Log::warning('Payment verification failed', [
            'payment_id' => $razorpay_payment_id,
            'order_id' => $order_id,
            'response_status' => $response->status() ?? 'unknown',
            'response_ok' => $response->ok() ?? false,
            'payment_status' => $response->ok() ? ($response->object()->status ?? 'N/A') : 'API call failed',
            'response_body' => $response->ok() ? 'success' : ($response->body() ?? 'N/A')
        ]);

        return ['status' => 'failed'];
    }
    public function gateway_name() {
        return 'razorpay';
    }

    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "INR";
    }

    public function supported_currency_list() {
        return ['INR'];
    }

    public function baseApi() {
        return 'https://api.razorpay.com/v1/';
    }
    public function cancel_subscription($subscription_id, $cancel_at_cycle_end = false)
    {
        abort_if(is_null($this->getApiKey()), 405, 'razorpay api key is missing');
        abort_if(is_null($this->getApiSecret()), 405, 'razorpay api secret is missing');

        $response = Http::withBasicAuth($this->getApiKey(), $this->getApiSecret())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseApi() . "subscriptions/{$subscription_id}/cancel", [
                'cancel_at_cycle_end' => $cancel_at_cycle_end ? 1 : 0
            ]);

        if ($response->ok()) {
            return [
                'status' => 'success',
                'subscription_data' => $response->object()
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->body()
        ];
    }

    /**
     * Pause a Razorpay subscription
     * @param string $subscription_id
     * @return array
     */
    public function pause_subscription($subscription_id)
    {
        abort_if(is_null($this->getApiKey()), 405, 'razorpay api key is missing');
        abort_if(is_null($this->getApiSecret()), 405, 'razorpay api secret is missing');

        $response = Http::withBasicAuth($this->getApiKey(), $this->getApiSecret())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseApi() . "subscriptions/{$subscription_id}/pause", [
                'pause_at' => 'now' // Pause immediately
            ]);

        if ($response->ok()) {
            return [
                'status' => 'success',
                'subscription_data' => $response->object()
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->body()
        ];
    }

    /**
     * Resume a paused Razorpay subscription
     * @param string $subscription_id
     * @return array
     */
    public function resume_subscription($subscription_id)
    {
        abort_if(is_null($this->getApiKey()), 405, 'razorpay api key is missing');
        abort_if(is_null($this->getApiSecret()), 405, 'razorpay api secret is missing');

        $response = Http::withBasicAuth($this->getApiKey(), $this->getApiSecret())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseApi() . "subscriptions/{$subscription_id}/resume", [
                'resume_at' => 'now' // Resume immediately
            ]);

        if ($response->ok()) {
            return [
                'status' => 'success',
                'subscription_data' => $response->object()
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->body()
        ];
    }

    /**
     * Fetch subscription details
     * @param string $subscription_id
     * @return array
     */
    public function fetch_subscription($subscription_id)
    {
        abort_if(is_null($this->getApiKey()), 405, 'razorpay api key is missing');
        abort_if(is_null($this->getApiSecret()), 405, 'razorpay api secret is missing');

        $response = Http::withBasicAuth($this->getApiKey(), $this->getApiSecret())
            ->get($this->baseApi() . "subscriptions/{$subscription_id}");

        if ($response->ok()) {
            return [
                'status' => 'success',
                'subscription_data' => $response->object()
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->body()
        ];
    }

    public function verify_webhook_signature($payload, $signature)
    {
        try {
            $webhook_secret = $this->getWebhookSecret();

            if (is_null($webhook_secret) || empty($webhook_secret)) {
                \Illuminate\Support\Facades\Log::warning('Webhook secret is not configured');
                return false;
            }

            if (is_null($signature) || empty($signature)) {
                \Illuminate\Support\Facades\Log::warning('Signature header is missing');
                return false;
            }
            \Illuminate\Support\Facades\Log::info('Webhook',[$webhook_secret]);
            \Illuminate\Support\Facades\Log::info('signature',[$signature]);
            //  Use the raw body captured by middleware
            $rawBody = request()->input('_raw_body') ?? request()->getContent();

            if (empty($rawBody)) {
                $rawBody = file_get_contents('php://input');
            }

            if (empty($rawBody)) {
                \Illuminate\Support\Facades\Log::warning('Raw body is empty for webhook verification');
                return false;
            }

            // Generate expected signature
            $expected_signature = hash_hmac('sha256', $rawBody, $webhook_secret);

            \Illuminate\Support\Facades\Log::info('Webhook Signature Debug', [
                'raw_body_length' => strlen($rawBody),
                'raw_body_hash' => hash('sha256', $rawBody),
                'webhook_secret_length' => strlen($webhook_secret),
                'received_signature' => $signature,
                'expected_signature' => $expected_signature,
                'match' => hash_equals($expected_signature, $signature)
            ]);

            return hash_equals($expected_signature, $signature);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Webhook signature verification exception', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
