<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class AdyenPay extends PaymentGatewayBase
{
    use ConvertUsdSupport, CurrencySupport, PaymentEnvironment;

    protected $apiKey;

    protected $clientKey;

    protected $merchantAccount;

    protected $webhookSecret;

    public function setApiKey($key): self
    {
        $this->apiKey = $key;

        return $this;
    }

    private function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setClientKey($key): self
    {
        $this->clientKey = $key;

        return $this;
    }

    private function getClientKey(): string
    {
        return $this->clientKey;
    }

    public function setMerchantAccount($merchantAccount): self
    {
        $this->merchantAccount = $merchantAccount;

        return $this;
    }

    public function setWebhookSecret($secret): self
    {
        $this->webhookSecret = $secret;

        return $this;
    }

    private function getMerchantAccount(): string
    {
        return $this->merchantAccount;
    }

    private function getBaseUrl(): string
    {
        return $this->getEnv()
            ? 'https://checkout-test.adyen.com/v71'
            : 'https://checkout-live.adyen.com/v71';
    }

    private function setAmount($amount): int
    {
        return intval(round($amount * 100));
    }

    public function charge_amount($amount): float
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return (float) $amount;
        }

        return (float) $this->get_amount_in_usd($amount);
    }

    public function charge_customer(array $args)
    {
        if ($this->charge_amount($args['amount']) < 100 && ! in_array($this->getCurrency(), ['USD', 'EUR'])) {
            abort(402, __('Amount must be greater than 100 for non-USD/EUR currencies'));
        }

        $flow_type = $args['flow_type'] ?? 'hosted';

        if ($flow_type === 'dropin') {
            $session = $this->createCheckoutSession($args);

            return view('paymentgateway::adyen', [
                'adyenSession' => $session,
                'adyenClientKey' => $this->getClientKey(),
                'environment' => $this->getEnv() ? 'test' : 'live',
            ]);
        }

        $paymentLink = $this->createPaymentLink([
            'order_id' => $args['order_id'],
            'title' => $args['title'],
            'description' => $args['description'] ?? $args['title'],
            'amount' => $this->charge_amount($args['amount']),
            'name' => $args['name'] ?? '',
            'email' => $args['email'] ?? '',
            'return_url' => route('adyen.ipn.return'),
            'payment_type' => $args['payment_type'] ?? 'web',
        ]);

        return redirect()->away($paymentLink['url']);
    }

    private function createPaymentLink(array $args): array
    {
        $amount = $this->setAmount($args['amount']);
        $currency = $this->getCurrency();

        $payload = [
            'amount' => [
                'currency' => $currency,
                'value' => $amount,
            ],
            'reference' => $args['order_id'],
            'merchantAccount' => $this->getMerchantAccount(),
            'shopperEmail' => $args['email'],
            'shopperName' => $args['name'],
            'shopperReference' => $args['email'] ?? Str::uuid()->toString(),
            'returnUrl' => $args['return_url'].'?'.http_build_query([
                'order_id' => $args['order_id'],
                'payment_type' => $args['payment_type'],
            ]),
            'expiresAt' => Carbon::now()->addHours(2)->toIso8601String(),
            'metadata' => [
                'payment_type' => $args['payment_type'] ?? 'web',
                'title' => $args['title'] ?? '',
                'description' => $args['description'] ?? '',
            ],
        ];

        $response = Http::withHeaders([
            'X-API-Key' => $this->getApiKey(),
            'Content-Type' => 'application/json',
        ])->post($this->getBaseUrl().'/paymentLinks', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Adyen payment link failed', ['response' => $response->json()]);
        throw new \Exception('Payment link creation failed. Please try again.');
    }

    private function createCheckoutSession(array $args): array
    {
        $amount = $this->setAmount($args['amount']);
        $currency = $this->getCurrency();
        $return_url = route('adyen.ipn.return');

        $payload = [
            'amount' => [
                'currency' => $currency,
                'value' => $amount,
            ],
            'reference' => $args['order_id'],
            'merchantAccount' => $this->getMerchantAccount(),
            'returnUrl' => $return_url.'?'.http_build_query([
                'order_id' => $args['order_id'],
                'payment_type' => $args['payment_type'] ?? 'web',
            ]),
            'shopperEmail' => $args['email'],
            'shopperName' => $args['name'],
            'shopperReference' => $args['email'] ?? Str::uuid()->toString(),
            'expiresAt' => Carbon::now()->addHours(2)->toIso8601String(),
            'channel' => 'Web',
            'shopperLocale' => app()->getLocale(),
            'metadata' => [
                'payment_type' => $args['payment_type'] ?? 'web',
                'title' => $args['title'] ?? '',
                'description' => $args['description'] ?? '',
            ],
        ];

        $response = Http::withHeaders([
            'X-API-Key' => $this->getApiKey(),
            'Content-Type' => 'application/json',
        ])->post($this->getBaseUrl().'/sessions', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Adyen checkout session failed', ['response' => $response->json()]);
        throw new \Exception('Checkout session creation failed. Please try again.');
    }

    public function ipn_response(array $requestData)
    {
        try {
            // Verify HMAC signature first
            if (! $this->verifyHmacSignature($requestData)) {
                throw new \Exception('Invalid HMAC signature');
            }

            // Extract notification item
            $notificationItem = $requestData['notificationItems'][0]['NotificationRequestItem'];

            if ($this->isValidAuthNotification($notificationItem)) {
                return $this->verified_data([
                    'order_id' => $notificationItem['merchantReference'],
                    'transaction_id' => $notificationItem['pspReference'],
                    'payment_type' => $notificationItem['additionalData']['metadata.payment_type'] ?? '',
                ]);
            }

            throw new \Exception('No valid AUTHORISATION event found');
        } catch (\Exception $e) {
            Log::error('Webhook Processing Error', [
                'error' => $e->getMessage(),
                'notification' => $requestData,
            ]);
            throw $e;
        }
    }

    private function isValidAuthNotification(array $notification): bool
    {
        $success = $notification['success'] ?? false;
        if (is_bool($success)) {
            $success = $success ? 'true' : 'false';
        }

        return $notification['eventCode'] === 'AUTHORISATION' && $success === 'true';
    }

    private function verifyHmacSignature(array $notification): bool
    {
        // Check if webhook secret is set
        if (empty($this->webhookSecret)) {
            Log::warning('Missing webhook secret for HMAC verification.');

            return false;
        }

        $notificationItem = $notification['notificationItems'][0]['NotificationRequestItem'] ?? null;
        if (! $notificationItem) {
            return false;
        }

        $signatureData = [
            $notificationItem['pspReference'],
            $notificationItem['originalReference'] ?? '',
            $notificationItem['merchantAccountCode'],
            $notificationItem['merchantReference'],
            $notificationItem['amount']['value'],
            $notificationItem['amount']['currency'],
            $notificationItem['eventCode'],
            $notificationItem['success'],
        ];

        if (is_bool($signatureData[7])) {
            $signatureData[7] = $signatureData[7] ? 'true' : 'false';
        }

        $dataToSign = implode(':', array_map(function ($item) {
            return str_replace(':', '\\:', $item);
        }, $signatureData));

        // Decode the hex secret into binary format
        $hmacKey = pack('H*', $this->webhookSecret);

        $calculatedHmac = base64_encode(hash_hmac('sha256', $dataToSign, $hmacKey, true));
        $receivedHmac = $notificationItem['additionalData']['hmacSignature'] ?? '';

        // Log::info('HMAC Verification Details', [
        //     'data_to_sign' => $dataToSign,
        //     'calculated_hmac' => $calculatedHmac,
        //     'received_hmac' => $receivedHmac,
        //     'webhook_secret' => $this->webhookSecret,
        // ]);

        return hash_equals($calculatedHmac, $receivedHmac);
    }

    public function supported_currency_list(): array
    {
        return [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'NZD', 'CHF', 'SEK',
            'NOK', 'DKK', 'HKD', 'SGD', 'MXN', 'PLN', 'CZK', 'HUF', 'RUB', 'ZAR',
        ];
    }

    public function charge_currency(): string
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }

        return 'USD';
    }

    public function gateway_name(): string
    {
        return 'adyen';
    }
}
