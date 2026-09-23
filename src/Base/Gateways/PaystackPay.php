<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Facades\Http; // Use Laravel HTTP Client
use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\NigeriaCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class PaystackPay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle
{
    use PaymentEnvironment, CurrencySupport,NigeriaCurrencySupport;

    protected $public_key;
    protected $secret_key;
    protected $merchant_email;
    protected $api_base_url = 'https://api.paystack.co/'; // Paystack API base URL

    public function setPublicKey($public_key)
    {
        $this->public_key = $public_key;
        return $this;
    }

    public function getPublicKey()
    {
        return $this->public_key;
    }

    public function setSecretKey($secret_key)
    {
        $this->secret_key = $secret_key;
        return $this;
    }

    public function getSecretKey()
    {
        return $this->secret_key;
    }

    public function setMerchantEmail($merchant_email)
    {
        $this->merchant_email = $merchant_email;
        return $this;
    }


    public function getMerchantEmail()
    {
        return $this->merchant_email;
    }

    /**
     * Makes a request to the Paystack API using Laravel HTTP Client.
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $endpoint API endpoint (e.g., 'transaction/initialize', 'transaction/verify/')
     * @param array $data Request body for POST, or query parameters for GET
     * @return array|null Decoded JSON response, or null on error
     */
    protected function makeApiRequest(string $method, string $endpoint, array $data = []): ?array
    {
        $request = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getSecretKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $url = $this->api_base_url . $endpoint;

        try {
            if ($method === 'POST') {
                $response = $request->post($url, $data);
            } elseif ($method === 'GET') {
                $response = $request->get($url, $data);
            } elseif ($method === 'PUT') {
                $response = $request->put($url, $data);
            } elseif ($method === 'DELETE') {
                $response = $request->delete($url, $data);
            } else {
                // Unsupported method
                return null;
            }

            if ($response->successful()) {
                return $response->json();
            } else {
                // Log error details for debugging
                $errorMessage = $response['message'] ?? 'Failed to initialize Paystack transaction.';
                abort(500,$errorMessage);
            }
        } catch (\Exception $e) {
            // Catch network errors or other exceptions
            $errorMessage = $response['message'] ?? 'Failed to initialize Paystack transaction.';
            abort(500,$errorMessage);
        }
    }

    public function recurring_interval_label($interval, $interval_count = 1)
    {
        $interval = strtolower(trim((string) $interval));
        $interval_count = max(1, (int) $interval_count);
        if ($interval_count > 1) {
            return $interval;
        }
        return match($interval) {
            'daily', 'day', 'days' => 'daily',
            'weekly', 'week', 'weeks' => 'weekly',
            'monthly', 'month', 'months' => 'monthly',
            'quarterly' => 'quarterly',
            'biannually' => 'biannually',
            'yearly', 'annual', 'year', 'years' => 'annually',
            default => 'monthly',
        };
    }

    public function getOrCreatePlan($plan_config)
    {
        if (!empty($plan_config['paystack_plan_code'])) {
            return ['status' => 'success', 'plan_code' => $plan_config['paystack_plan_code'], 'created' => false];
        }
        $interval = $this->recurring_interval_label(
            $plan_config['interval'] ?? 'monthly',
            $plan_config['interval_count'] ?? 1
        );
        $payload = [
            'name' => $plan_config['title'] ?? 'Recurring Plan',
            'amount' => (int) round($this->charge_amount($plan_config['price'] ?? $plan_config['amount'] ?? 0)),
            'interval' => $interval,
            'description' => $plan_config['description'] ?? ($plan_config['title'] ?? 'Recurring Plan'),
            'currency' => $this->charge_currency(),
        ];
        if (($plan_config['interval_count'] ?? 1) > 1) {
            $payload['invoice_limit'] = 0;
        }
        $response = $this->makeApiRequest('POST', 'plan', $payload);
        if (!empty($response['status']) && !empty($response['data']['plan_code'])) {
            return ['status' => 'success', 'plan_code' => $response['data']['plan_code'], 'created' => true, 'plan_data' => $response['data']];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Failed to create Paystack plan.'];
    }

    public function charge_customer_recurring(array $args)
    {
        $plan_config = $args['plan_config'] ?? [
            'title' => $args['title'] ?? 'Monthly Donation',
            'price' => $args['amount'],
            'description' => $args['description'] ?? ($args['title'] ?? 'Monthly subscription'),
            'interval' => $args['recurring_interval'] ?? 'monthly',
            'interval_count' => $args['recurring_interval_count'] ?? 1,
        ];
        $plan = $this->getOrCreatePlan($plan_config);
        if ($plan['status'] !== 'success') {
            throw new \RuntimeException('Paystack plan error: ' . ($plan['message'] ?? 'unknown'));
        }
        $args['plan_code'] = $plan['plan_code'];
        $args['is_subscription'] = true;
        return $this->charge_customer($args);
    }

    public function ipn_response_recurring(array $args = [])
    {
        $payment_data = $this->ipn_response($args);
        if (($payment_data['status'] ?? 'failed') === 'complete') {
            $payment_data['is_recurring'] = true;
        }
        return $payment_data;
    }

    public function create_subscription($customer_code, $plan_code)
    {
        $response = $this->makeApiRequest('POST', 'subscription', [
            'customer' => $customer_code,
            'plan' => $plan_code,
        ]);
        if (!empty($response['status'])) {
            return ['status' => 'success', 'subscription_data' => $response['data'] ?? []];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Failed to create subscription.'];
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        // Paystack disables via token or subscription code; immediate disable.
        foreach (['subscription/disable', 'subscription/' . $subscription_id . '/disable'] as $endpoint) {
            $response = $this->makeApiRequest('POST', $endpoint, ['code' => $subscription_id, 'token' => $subscription_id]);
            if (!empty($response['status'])) {
                return ['status' => 'success', 'subscription_data' => $response['data'] ?? []];
            }
        }
        return ['status' => 'failed', 'message' => 'Failed to disable subscription.'];
    }

    public function pause_subscription($subscription_id)
    {
        return $this->cancel_subscription($subscription_id, false);
    }

    public function resume_subscription($subscription_id)
    {
        $response = $this->makeApiRequest('POST', 'subscription/enable', ['code' => $subscription_id, 'token' => $subscription_id]);
        if (!empty($response['status'])) {
            return ['status' => 'success', 'subscription_data' => $response['data'] ?? []];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Failed to enable subscription.'];
    }

    public function fetch_subscription($subscription_id)
    {
        $response = $this->makeApiRequest('GET', 'subscription/' . $subscription_id);
        if (!empty($response['status'])) {
            return ['status' => 'success', 'subscription_data' => $response['data'] ?? []];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Subscription not found.'];
    }

    public function verify_webhook_signature($payload, $signature)
    {
        if (empty($this->getSecretKey()) || empty($signature) || empty($payload)) {
            return false;
        }
        $expected = hash_hmac('sha512', $payload, $this->getSecretKey());
        return hash_equals($expected, (string) $signature);
    }

    /**
     * @inheritDoc
     * @param int|float $amount
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            // Paystack expects amount in kobo (cents) for NGN, or the base unit for other supported currencies.
            // If the currency is NGN, ensure it's in kobo. Otherwise, pass as is for GHS, ZAR.
            if ($this->getCurrency() === 'NGN') {
                return $amount * 100;
            }
            return $amount;
        }
        return $this->get_amount_in_ngn($amount);
    }


    /**
     * @inheritDoc
     * @param array $args;
     * @return array ['status','type','order_id','transaction_id'];
     */
    public function ipn_response(array $args = [])
    {
        $this->setConfig(); // Ensure keys are set
        $request = request(); // Use Laravel's request helper

        $reference = $request->reference ?? null; // Get reference from Paystack redirect

        if (!$reference) {
            return ['status' => 'failed', 'message' => 'Paystack reference not found.'];
        }

        $paymentDetails = $this->makeApiRequest('GET', 'transaction/verify/' . $reference);

        if (isset($paymentDetails['status']) && $paymentDetails['status'] === true && ($paymentDetails['data']['status'] ?? null) === 'success') {
            $meta_data = $paymentDetails['data']['metadata'] ?? [];
            $order_id_from_metadata = $meta_data['custom_fields'][0]['value'] ?? null; // Assuming custom_fields[0] is order_id
            $payment_type_from_metadata = $meta_data['custom_fields'][1]['value'] ?? 'random'; // Assuming custom_fields[1] is type

            return $this->verified_data([
                'transaction_id' => $paymentDetails['data']['reference'],
                'type' => $payment_type_from_metadata,
                'order_id' => $order_id_from_metadata,
            ]);
        }

        return ['status' => 'failed', 'message' => $paymentDetails['message'] ?? 'Payment verification failed.'];
    }

    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {
        $amount = $this->charge_amount($args['amount']); // This will return amount in kobo for NGN, or base unit for others

        $order_id_for_paystack = $args['order_id'];

        $paystack_request_data = [
            'amount' => $amount, // Amount in kobo (for NGN) or base unit (GHS, ZAR)
            'email' => $args['email'],
            'currency' => $this->charge_currency(),
            'reference' => 'PAYSTACK_' . uniqid() . '_' . $order_id_for_paystack, // Unique transaction reference
            'metadata' => [

                'custom_fields' => [
                    [
                        'display_name' => 'Order ID',
                        'variable_name' => 'order_id',
                        'value' => $order_id_for_paystack, // Original order ID
                    ],
                    [
                        'display_name' => 'Payment Type',
                        'variable_name' => 'type',
                        'value' => $args['payment_type'] ?? 'random',
                    ],
                    [
                        'display_name' => 'Track',
                        'variable_name' => 'track',
                        'value' => $args['track'],
                    ],
                    // Add any other custom fields you need
                ],
            ],
        ];

        // Subscription checkout: attach the plan so Paystack auto-charges renewals.
        if (!empty($args['plan_code'])) {
            $paystack_request_data['plan'] = $args['plan_code'];
        }

        // Make the initialization API call
        $response = $this->makeApiRequest('POST', 'transaction/initialize', $paystack_request_data);
        if (!is_array($response)) {
            return back()->with(['msg' => 'Invalid response from Paystack.', 'type' => 'danger']);
        }

        if (isset($response['status']) && $response['status'] === true && isset($response['data']['authorization_url'])) {
            $paystack_data = [
                'currency' => $this->charge_currency(),
                'price' => $amount / 100, // Display amount in actual currency for the user on the view
                'package_name' => $args['title'],
                'name' => $args['name'],
                'email' => $args['email'],
                'order_id' => $order_id_for_paystack,
                'track' => $args['track'],
                'reference' => $response['data']['reference'], // Paystack's transaction reference
                'type' => $args['payment_type'] ?? 'random',
                'merchantEmail' => $this->getMerchantEmail(), // Although not strictly needed for frontend, good to have
                'secretKey' => $this->getSecretKey(), // Not needed for frontend, but useful for debugging
                'publicKey' => $this->getPublicKey(),
                'authorization_url' => $response['data']['authorization_url'], // In case you want to redirect
                'ipn_url' => $args['ipn_url']
            ];

            return view('paymentgateway::paystack', ['paystack_data' => $paystack_data]);

        } else {
            // Handle error from Paystack API initialization
            $errorMessage = $response['message'] ?? 'Failed to initialize Paystack transaction.';
            abort(500,$errorMessage);
        }
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ['GHS', 'NGN', 'ZAR'];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "NGN"; // Default to NGN if the chosen currency is not directly supported
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return 'paystack';
    }

    /**
     * Sets the configuration for the gateway.
     * With no package, this primarily ensures that internal properties are set.
     * @param array $config
     */
    public function setConfig($config = [])
    {
        // If config is passed, use it to set the keys
        if (isset($config['publicKey'])) {
            $this->setPublicKey($config['publicKey']);
        }
        if (isset($config['secretKey'])) {
            $this->setSecretKey($config['secretKey']);
        }
        if (isset($config['merchantEmail'])) {
            $this->setMerchantEmail($config['merchantEmail']);
        }
    }
}
