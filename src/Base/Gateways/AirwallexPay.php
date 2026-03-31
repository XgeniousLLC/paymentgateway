<?php

//using payment link
namespace Xgenious\Paymentgateway\Base\Gateways;

use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class AirwallexPay extends PaymentGatewayBase
{
    use PaymentEnvironment, CurrencySupport;

    protected $client_id;
    protected $api_key;
    protected $token;
    protected $tokenExpiry;

    public function setClientId($client_id)
    {
        $this->client_id = $client_id;
        return $this;
    }

    private function getClientId()
    {
        return $this->client_id;
    }

    public function setApiKey($api_key)
    {
        $this->api_key = $api_key;
        return $this;
    }

    private function getApiKey()
    {
        return $this->api_key;
    }

    private function getBaseUrl()
    {
        return $this->getEnv()
            ? 'https://api-demo.airwallex.com'
            : 'https://api.airwallex.com';
    }

    protected function getAuthToken($debug = false)
    {
        // Check if we have a valid token already
        if ($this->token && $this->tokenExpiry && $this->tokenExpiry > Carbon::now()) {
            return $this->token;
        }

        try {
            // Get credentials
            $clientId = $this->getClientId();
            $apiKey = $this->getApiKey();
            $baseUrl = $this->getBaseUrl();

            // Validate credentials
            if (empty($clientId)) {
                throw new \Exception('Airwallex client ID is missing');
            }

            if (empty($apiKey)) {
                throw new \Exception('Airwallex API key is missing');
            }

            // Prepare headers exactly as specified in documentation
            $headers = [
                'x-api-key' => $apiKey,
                'x-client-id' => $clientId,
                'Content-Type' => 'application/json'
            ];

            // Make the request
            $response = Http::withHeaders($headers)
                ->post($baseUrl . '/api/v1/authentication/login');

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['token'])) {
                    $this->token = $data['token'];
                    return $this->token;
                } else {
                    throw new \Exception('Airwallex authentication response missing token');
                }
            } else {
                $errorData = $response->json();
                $errorMessage = 'Airwallex authentication failed: ' . $response->status();

                if (isset($errorData['code'])) {
                    $errorMessage .= ' (Code: ' . $errorData['code'] . ')';
                }

                if (isset($errorData['message'])) {
                    $errorMessage .= ' - ' . $errorData['message'];
                }

                // Log error details
                if (class_exists('\Log')) {
                    \Log::error('Airwallex Auth Error', [
                        'status' => $response->status(),
                        'response' => $errorData,
                        'environment' => $this->getEnv() ? 'demo' : 'production',
                        'url' => $baseUrl . '/api/v1/authentication/login'
                    ]);
                }

                throw new \Exception($errorMessage);
            }
        } catch (\Exception $e) {
            // Log the exception
            if (class_exists('\Log') && method_exists($e, 'response') && $e->response()) {
                \Log::error('Airwallex HTTP Exception', [
                    'message' => $e->getMessage(),
                    'response' => $e->response()->json() ?? 'No JSON response'
                ]);
            }

            throw $e;
        }
    }

    private function createPaymentLink(array $args)
    {
        $token = $this->getAuthToken();
        $amount = $this->setAmount($args['amount']);

        try {
            $requestId = uniqid('req_', true);
            // Basic required payload structure according to documentation
            $payload = [
                'request_id' => $requestId,
                'amount' => $amount,
                'currency' => $this->getCurrency(),
                'title' => $args['title'] ?? 'Payment',
                'description' => $args['description'] ?? ($args['title'] ?? 'Payment'),
                'return_url' => $args['ipn_url'],
                'reusable' => false,
                'expired_time' => Carbon::now()->addDays(7)->toIso8601String(),
                'metadata' => [
                    'order_id' => $args['order_id'],
                    'customer_email' => $args['email'] ?? null,
                    'payment_type' => $args['payment_type'],
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post($this->getBaseUrl() . '/api/v1/pa/payment_links/create', $payload);

            if ($response->successful()) {
                return $response->json();
            } else {
                $errorData = $response->json();
                $errorMessage = 'Payment link creation failed: ' . $response->status();

                if (isset($errorData['code'])) {
                    $errorMessage .= ' (Code: ' . $errorData['code'] . ')';
                }

                if (isset($errorData['message'])) {
                    $errorMessage .= ' - ' . $errorData['message'];
                }

                throw new \Exception($errorMessage);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function setAmount($amount)
    {
        if ($this->getExchangeRate() > 0) {
            return round($amount * $this->getExchangeRate(), 2);
        }
        return round($amount, 2); // Always round to 2 decimal places
    }


    public function charge_amount($amount)
    {
        return $this->setAmount($amount);
    }

    public function charge_customer(array $args)
    {
        $paymentLink = $this->createPaymentLink([
            'order_id' => $args['order_id'],
            'title' => $args['title'],
            'description' => $args['description'] ?? $args['title'],
            'amount' => $args['amount'],
            'name' => $args['name'] ?? '',
            'email' => $args['email'] ?? '',
            'ipn_url' => $args['ipn_url'],
            'payment_type' => $args['payment_type'],
        ]);

        session()->put('airwallex_payment_link_id', $paymentLink['id']);
        session()->put('airwallex_order_id', $args['order_id']);
        return redirect()->away($paymentLink['url']);
    }

    public function ipn_response(array $args = []): array
    {
        $payment_link_id = session()->get('airwallex_payment_link_id');
        session()->forget('airwallex_payment_link_id');
        $order_id = session()->get('airwallex_order_id');
        session()->forget('airwallex_order_id');

        try {
            $token = $this->getAuthToken();
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->get($this->getBaseUrl() . '/api/v1/pa/payment_links/' . $payment_link_id);

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['status'] ?? '';

                if ($status === 'PAID') {
                    $paymentIntentId = $data['payment_intent']['id'] ?? '';
                    return $this->verified_data([
                        'transaction_id' => $paymentIntentId ?: $payment_link_id,
                        'order_id' => $order_id
                    ]);
                }
            }

            return ['status' => 'failed', 'order_id' => $order_id];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'order_id' => $order_id, 'message' => $e->getMessage()];
        }
    }

    public function supported_currency_list(): array
    {
        return [
            'AUD', 'CNY', 'USD', 'HKD', 'EUR', 'GBP', 'CAD', 'SGD',
            'CHF', 'NZD', 'JPY', 'THB', 'SEK', 'NOK', 'DKK', 'HUF',
            'CZK', 'PLN', 'RON', 'IDR', 'MYR', 'PHP', 'TRY', 'INR',
            'VND', 'KRW', 'BDT', 'LKR', 'NPR', 'PKR', 'MXN', 'ILS',
            'ZAR', 'BHD', 'EGP', 'AED', 'SAR', 'MAD', 'CLP', 'ARS',
            'BRL', 'COP', 'BOB', 'PEN', 'UYU', 'AOA', 'BGN', 'LSL',
            'MGA', 'MWK', 'NAD', 'NGN', 'RWF', 'XOF', 'BWP', 'GMD',
            'KES', 'PYG', 'ZMW', 'XAF'
        ];
    }


    public function charge_currency()
    {
        return $this->getCurrency();
    }

    public function gateway_name(): string
    {
        return 'airwallex';
    }
}