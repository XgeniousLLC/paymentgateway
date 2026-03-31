<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\THBCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Illuminate\Support\Facades\Http;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Models\PaymentMeta;
use Illuminate\Support\Str;

class XenditPay extends PaymentGatewayBase
{
    use CurrencySupport, PaymentEnvironment,THBCurrencySupport;

    protected $secret_key;
    protected $webhook_token;
    protected $api_version = "2.0";

    /**
     * @inheritDoc
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $this->is_decimal($amount) ? $amount : number_format((float)$amount,2,'.','');
        }
        return $this->is_decimal( $this->get_amount_in_thb($amount)) ? $this->get_amount_in_thb($amount) :number_format((float) $this->get_amount_in_thb($amount),2,'.','');
    }

    /**
     * @inheritDoc
     */
    public function ipn_response(array $args = [])
    {
        $payload = \request()->all();
        if (empty($payload)) {
            return ['status' => 'failed'];
        }

        // Verify webhook signature
        if (!$this->verifyWebhookSignature()) {
            return ['status' => 'failed'];
        }

        // Get the payment ID from callback data
        $payment_id = $payload['id'] ?? '';
        $payment = PaymentMeta::where('meta_data->payment_id', $payment_id)->first();

        if (!$payment) {
            return ['status' => 'failed'];
        }

        $status = $payload['status'] ?? '';
        if ($status === 'PAID') {
            return $this->verified_data([
                'status' => 'complete',
                'transaction_id' => $payment_id,
                'order_id' => substr($payment->order_id, 5, -5),
            ]);
        }

        return ['status' => 'failed'];
    }

    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {

        $order_id = PaymentGatewayHelpers::wrapped_id($args['order_id']);
        $amount = $this->charge_amount($args['amount']);

        $data = [
            'external_id' => $order_id,
            'amount' => $amount,
            'currency' => $this->charge_currency(),
//            'payment_methods' => ['CREDIT_CARD', 'THB_PROMPT_PAY', 'THB_QR'],
            'success_redirect_url' => $args['ipn_url'] . "?order_id={$order_id}",
            'failure_redirect_url' => $args['cancel_url'] . "?order_id={$order_id}",
            'customer' => [
                'given_names' => $args['name'],
                'email' => $args['email'],
                'mobile_number' => $args['phone'] ?? '00000',
            ],
            'items' => [
                [
                    'name' => $args['title'] ?? 'Payment for Order #' . $args['order_id'],
                    'quantity' => 1,
                    'price' => $amount,
                    'category' => $args['payment_type'] ?? 'PAYMENT',
                ]
            ],
            'should_send_email' => true,
            'invoice_duration' => 86400,
        ];

        $response = Http::withHeaders($this->getHeaders())
            ->post($this->get_api_url() . '/v2/invoices', $data);

        if ($response->successful()) {
            $result = $response->object();

            $payment_meta = PaymentMeta::create([
                'gateway' => 'xendit',
                'amount' => $amount,
                'order_id' => $order_id,
                'meta_data' => json_encode([
                    'payment_id' => $result->id,
                    'status' => $result->status,
                    'invoice_url' => $result->invoice_url,
                ]),
                'session_id' => $result->id,
                'type' => $args['payment_type'] ?? 'PAYMENT',
                'track' => Str::random(60),
            ]);

            return \redirect()->away($result->invoice_url);
        }

        abort(500, 'Xendit API Error: ' . ($response->object()->message ?? 'Unknown error'));
    }
    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ['IDR', 'PHP', 'THB', 'VND', 'MYR'];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "THB";
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return "xendit";
    }

    /* set secret key */
    public function setSecretKey($secret_key)
    {
        $this->secret_key = $secret_key;
        return $this;
    }

    /* get secret key */
    private function getSecretKey()
    {
        if (empty($this->secret_key)) {
            $this->secret_key = get_static_option('xendit_secret_key');
        }
        return $this->secret_key;
    }


    /* set secret key */
    public function setWebhookToken($webhook_token)
    {
        $this->webhook_token = $webhook_token;
        return $this;
    }

    /* get secret key */
    private function getWebhookToken()
    {
        return $this->webhook_token;
    }


    public function getHeaders()
    {
        $secretKey = $this->getSecretKey();

        return [
            'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

    }

    public function get_api_url()
    {
        return 'https://api.xendit.co';
    }

    private function verifyWebhookSignature()
    {
        $callback_token = request()->header('x-callback-token');
        $webhook_token = config('xendit.webhook_token');

        if (empty($callback_token) || empty($webhook_token)) {
            return false;
        }

        return hash_equals($webhook_token, $callback_token);
    }

    public function verify_payment($payment_id)
    {
        $response = Http::withHeaders($this->getHeaders())
            ->get($this->get_api_url() . '/v2/invoices/' . $payment_id);

        if ($response->successful()) {
            $result = $response->object();

            if ($result->status === 'PAID') {
                return [
                    'status' => 'complete',
                    'transaction_id' => $result->id,
                    'payment_meta' => [
                        'id' => $result->id,
                        'external_id' => $result->external_id,
                        'amount' => $result->amount,
                        'status' => $result->status,
                        'paid_amount' => $result->paid_amount,
                        'payment_method' => $result->payment_method,
                        'paid_at' => $result->paid_at,
                        'payer_email' => $result->payer_email,
                    ]
                ];
            }

            return [
                'status' => strtolower($result->status),
                'message' => 'Payment ' . strtolower($result->status),
            ];
        }

        return [
            'status' => 'failed',
            'message' => 'Failed to verify payment'
        ];
    }

    public function request_verify_payment(string $invoice_id){
        return Http::withHeaders($this->getHeaders())
            ->get($this->get_api_url() . '/v2/invoices/' . $invoice_id);
    }
}