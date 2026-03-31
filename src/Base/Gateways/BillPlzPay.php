<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Models\PaymentMeta;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\MyanmarCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Illuminate\Support\Str;

class BillPlzPay extends PaymentGatewayBase
{
    use CurrencySupport, MyanmarCurrencySupport, PaymentEnvironment;

    public $key; // Billplz API Secret Key
    public $version; // API Version (e.g., 'v3') - implicitly handled by base URL
    public $x_signature; // Billplz X-Signature Key for webhook verification
    public $collection_name; // Billplz Collection ID

    protected $api_base_url; // Will be set based on environment

    public function __construct()
    {
        // Set default API base URL based on environment
        $this->setApiBaseUrl();
    }

    protected function setApiBaseUrl(): void
    {
        // Convert string value to boolean if needed
        $isSandbox = is_bool($this->getEnv())
            ? $this->getEnv()
            : ($this->getEnv() === 'sandbox' || $this->getEnv() === 'test' || $this->getEnv() === '1');

        $this->api_base_url = $isSandbox
            ? 'https://www.billplz-sandbox.com/api/v3/'
            : 'https://www.billplz.com/api/v3/';
    }

    public function getCollectionName()
    {
        return $this->collection_name;
    }

    public function setCollectionName($collection_name)
    {
        $this->collection_name = $collection_name;
        return $this;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function setVersion($version)
    {
        $this->version = $version;
        return $this;
    }

    public function getXsignature()
    {
        return $this->x_signature;
    }

    public function setXsignature($x_signature)
    {
        $this->x_signature = $x_signature;
        return $this;
    }

    /**
     * @inheritDoc
     * Billplz expects amount in cents/sen for MYR.
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return (int) ($amount * 100); // Ensure integer in sen
        }
        // If your system allows other currencies to be converted to MYR for Billplz
        return (int) ($this->get_amount_in_myr($amount) * 100); // Convert to MYR then to sen
    }

    /**
     * Makes a request to the Billplz API.
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $endpoint API endpoint (e.g., 'bills')
     * @param array $data Request body for POST, or query parameters for GET
     * @return array|null Decoded JSON response, or null on error
     */
    protected function makeApiRequest(string $method, string $endpoint, array $data = []): ?array
    {
        $request = Http::withBasicAuth($this->getKey(), '') // Billplz uses API Key as username, empty password
        ->withHeaders([
            'Accept' => 'application/json',
        ]);

        $url = $this->api_base_url . $endpoint;

        try {
            if ($method === 'POST') {
                $response = $request->post($url, $data);
            } elseif ($method === 'GET') {
                $response = $request->get($url, $data);
            } else {
                abort(405, 'Unsupported HTTP method.');
            }

            if ($response->successful()) {
                return $response->json();
            } else {
                abort($response->status(), 'API request failed: ' . $response->body());
            }
        } catch (\Exception $e) {
            abort(500, 'API exception: ' . $e->getMessage());
        }
    }


    /**
     * @inheritDoc
     * Billplz IPN (Webhook) and Redirect callback handling.
     * $args will contain the request parameters.
     */
    public function ipn_response(array $args = [])
    {
        $this->setConfig(); // Ensure API keys are set

        $request_params = request()->all(); // Use all request parameters for robustness

        // Extract relevant Billplz parameters, using 'billplz_' prefix if present
        $bill_id = $request_params['billplz_id'] ?? $request_params['id'] ?? null;
        $bill_status = $request_params['billplz_paid'] ?? $request_params['paid'] ?? null;
        $x_signature_from_request = $request_params['billplz_x_signature'] ?? $request_params['x_signature'] ?? null;
        $order_id_from_name = $request_params['billplz_name'] ?? $request_params['name'] ?? null;

        // Extract actual order_id from the 'name' field
        $original_order_id = $request_params['billplz_reference_1'] ?? $request_params['reference_1'] ?? null;
//        if ($order_id_from_name && Str::contains($order_id_from_name, 'ID#')) {
//            $wrapped_id = Str::after($order_id_from_name, 'ID#');
//            $original_order_id = PaymentGatewayHelpers::unwrap_id($wrapped_id); // Unwrap if wrapped
//        }

        // --- Manual X-Signature Verification ---
        $expected_x_signature_key = $this->getXsignature();
        if ($expected_x_signature_key && $x_signature_from_request) {
            // Parameters used for signature generation (alphabetical order)
            // Values should be as received from Billplz, including string 'true'/'false' for boolean
            $signature_data_payload = [];
            foreach ([
                         'amount', 'bill_url', 'collection_id', 'due_at', 'email', 'id',
                         'mobile', 'name', 'paid', 'paid_amount', 'state', 'url'
                     ] as $param) {
                // Check for both 'billplz_' prefixed and non-prefixed versions
                $value = $request_params['billplz_' . $param] ?? $request_params[$param] ?? null;
                if (!is_null($value)) {
                    $signature_data_payload[] = $param . $value;
                }
            }

            $concatenated_string = implode('|', $signature_data_payload);
            $calculated_signature = hash_hmac('sha256', $concatenated_string, $expected_x_signature_key);

            if (!hash_equals($calculated_signature, $x_signature_from_request)) {
                error_log("Billplz IPN: X-Signature mismatch for bill ID: " . $bill_id . " | Received: " . $x_signature_from_request . " | Calculated: " . $calculated_signature . " | Payload: " . $concatenated_string);
                return ['status' => 'failed', 'message' => 'X-Signature mismatch.', 'order_id' => $original_order_id];
            }
        } else {
            // For production, this should ideally lead to failure or a strict warning
            error_log("Billplz IPN: X-Signature key not configured or not found in request. Proceeding without signature verification (HIGH RISK).");
        }
        // --- End Manual X-Signature Verification ---

        // Check payment status
        if ($bill_id && $bill_status === 'true') {
            return $this->verified_data([
                'status' => 'complete',
                'transaction_id' => $bill_id,
                'order_id' => $original_order_id,
                'message' => 'Payment successful and verified.',
            ]);
        }

        return ['status' => 'failed', 'message' => 'Payment not successful or verification failed.', 'order_id' => $original_order_id];
    }


    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {
        $this->setConfig(); // Ensure API keys are set

        // Required parameters for Billplz create bill API
        $bill_data = [
            'collection_id' => $this->getCollectionName(),
            'email' => $args['email'],
            'mobile' => $args['phone'] ?? null, // Mobile is optional
            'name' => $args['name'] . ' ID#' . PaymentGatewayHelpers::wrapped_id($args['order_id']),
            'amount' => $this->charge_amount($args['amount']), // Amount in sen (e.g., 1000 for RM10.00)
            'callback_url' => $args['ipn_url'], // For webhook notifications (server-to-server)
            'description' => $args['description'] ?? 'Payment for order ' . $args['order_id'],
            'redirect_url' => $args['success_url'], // Where Billplz redirects the user after payment
            'reference_1_label' => 'Order ID',
            'reference_1' => $args['order_id'],
            'reference_2_label' => 'Payment Type',
            'reference_2' => $args['payment_type'] ?? 'random',
        ];

        // Filter out null values for optional fields
        $bill_data = array_filter($bill_data, fn($value) => !is_null($value));

        $response = $this->makeApiRequest('POST', 'bills', $bill_data);

        if (isset($response['id']) && $response['id']) {
            PaymentMeta::create([
                'gateway' => 'billplz',
                'amount' => $args['amount'],
                'order_id' => PaymentGatewayHelpers::wrapped_id($args['order_id']),
                'meta_data' => json_encode([
                    'bill_id' => $response['id'],
                    'bill_url' => $response['url'],
                    'collection_id' => $this->getCollectionName(),
                ]),
                'type' => $args['payment_type'],
                'track' => $args['track'] ?? Str::random(60),
            ]);

            return redirect()->to($response['url']);
        } else {
            $errorMessage = $response['error']['message'] ?? 'Failed to create Billplz bill.';
            return back()->with(['msg' => $errorMessage, 'type' => 'danger']);
        }
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ['MYR'];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        return 'MYR';
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return 'billplz';
    }

    /**
     * Sets the configuration for the gateway.
     * This method retrieves configuration from your application's settings
     * (e.g., environment variables, database).
     */
    public function setConfig($config = [])
    {
        // If config is passed, use it to set the keys
        if (isset($config['key'])) {
            $this->setKey($config['key']);
        }
        if (isset($config['x_signature'])) {
            $this->setXsignature($config['x_signature']);
        }
        if (isset($config['collection_name'])) {
            $this->setCollectionName($config['collection_name']);
        }
        if (isset($config['environment'])) {
            $this->setEnv($config['environment']);
        }
        // After setting environment, update base URL
        $this->setApiBaseUrl();
    }
}