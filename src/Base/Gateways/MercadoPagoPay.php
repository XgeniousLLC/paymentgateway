<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Client\PreApprovalPlan\PreApprovalPlanClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Resources\Preference;
use MercadoPago\Resources\Payment;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoPay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle
{
    use PaymentEnvironment, CurrencySupport;

    protected $client_id;
    protected $client_secret;
    protected $access_token;

    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list(), true)) {
            return (float) $amount;
        }
        return (float) $this->get_amount_in_brl($amount);
    }

    /**
     * get_amount_in_brl()
     * @since 1.0.0
     * this function return any amount to usd based on user given currency conversation value,
     * it will not work if admin did not give currency conversation rate
     */
    protected function get_amount_in_brl($amount)
    {
        if ($this->getCurrency() === 'BRL') {
            return (float) $amount;
        }
        $payable_amount = $this->make_amount_in_brl($amount, $this->getCurrency());
        if ($payable_amount < 1) {
            return $payable_amount . __('amount is not supported by ' . $this->gateway_name());
        }
        return (float) $payable_amount;
    }

    /**
     * convert amount to brl currency base on conversation given by admin
     */
    protected function make_amount_in_brl($amount, $currency)
    {
        $output = 0;
        $all_currency = GlobalCurrency::script_currency_list();
        foreach ($all_currency as $cur => $symbol) {
            if ($cur === 'BRL') {
                continue;
            }
            if ($cur == $currency) {
                $exchange_rate = !empty($this->getExchangeRate()) ? $this->getExchangeRate() : config('paymentgateway.brl_exchange_rate');
                $output = (float) $amount * (float) $exchange_rate;
            }
        }
        return $output;
    }

    public function ipn_response(array $args = [])
    {
        $this->setAccessToken();
        $request = request();
        $return_status = $request->status;
        $return_merchant_order_id = $request->merchant_order_id;
        $return_payment_id = $request->payment_id;

        try {
            $paymentClient = new PaymentClient();
            $payment_details = $paymentClient->get($return_payment_id);

            $order_id = $payment_details->order->id;
            $payment_status = $payment_details->status;
            $payment_metadata = $payment_details->metadata;
            $payment_metadata_order_id = $payment_details->metadata->order_id;

            if ($return_status === $payment_status && $return_merchant_order_id === $order_id) {
                return $this->verified_data([
                    'transaction_id' => $return_payment_id,
                    'order_id' => substr($payment_metadata_order_id, 5, -5)
                ]);
            }
        } catch (MPApiException $e) {
            $errorMessage = $e->getMessage();
            throw new \Exception("MercadoPago API Error: " . $errorMessage);
        }

        return ['status' => 'failed'];
    }

    public function charge_customer(array $args)
    {
        try {
            // Ensure charge_amount is a float
            $charge_amount = (float) $this->charge_amount($args['amount']);
            $order_id = random_int(1234, 99999) . $args['order_id'] . random_int(1234, 99999);

            $this->setAccessToken();

            $client = new PreferenceClient();

            # Building an item
            $item = [
                "id" => $order_id,
                "title" => $args['title'],
                "quantity" => 1,
                // Ensure unit_price is a float
                "unit_price" => $charge_amount
            ];

            $preferenceData = [
                "items" => [$item],
                "external_reference" => $order_id,
                "back_urls" => [
                    "success" => $args['ipn_url'],
                    "failure" => $args['cancel_url'],
                    "pending" => $args['cancel_url']
                ],
                "auto_return" => "approved",
                "metadata" => [
                    "order_id" => $order_id,
                    "payment_type" => $args['payment_type'],
                ]
            ];


            $preference = $client->create($preferenceData);


            return redirect()->away($preference->init_point);
        } catch (MPApiException $e) {
            // Get the API response for detailed error information
            $apiResponse = $e->getApiResponse();
            $content = $apiResponse->getContent();

            // Create a more user-friendly error message
            $errorMessage = 'Payment initialization failed';

            if (isset($content['message'])) {
                $errorMessage .= ': ' . $content['message'];
            }

            if (isset($content['cause'])) {
                if (is_array($content['cause'])) {
                    foreach ($content['cause'] as $cause) {
                        if (isset($cause['code']) && isset($cause['description'])) {
                            $errorMessage .= ' - ' . $cause['code'] . ': ' . $cause['description'];
                        }
                    }
                } else {
                    $errorMessage .= ' - ' . $content['cause'];
                }
            }

            throw new \Exception($errorMessage);
        } catch (\Exception $e) {
            throw new \Exception('Payment initialization failed: ' . $e->getMessage());
        }
    }

    public function webhook_response()
    {
        $this->setAccessToken();
        $request = request();
        $payment_type = $request->type;
        $payment_id = $request->data->id;

        if ($payment_type === 'payment') {
            try {
                $paymentClient = new PaymentClient();
                $payment_details = $paymentClient->get($payment_id);

                $order_id = $payment_details->order->id;
                $payment_status = $payment_details->status;
                $payment_metadata_order_id = $payment_details->metadata->order_id;
                $payment_metadata_payment_type = $payment_details->metadata->payment_type ?? 'unknown';

                if ($payment_status === 'approved') {
                    return $this->verified_data([
                        'transaction_id' => $payment_id,
                        'order_id' => substr($payment_metadata_order_id, 5, -5),
                        'payment_type' => $payment_metadata_payment_type
                    ]);
                }
            } catch (MPApiException $e) {
                $errorMessage = $e->getMessage();
                throw new \Exception("MercadoPago API Error: " . $errorMessage);
            }
        }
        return ['status' => 'failed'];
    }

    protected function setAccessToken()
    {
        $accessToken = $this->getClientSecret();

        if (empty($accessToken)) {
            throw new \Exception('MercadoPago access token is required');
        }

        MercadoPagoConfig::setAccessToken($accessToken);
        return $accessToken;
    }

    public function recurring_frequency($interval, $interval_count = 1)
    {
        $interval = strtolower(trim((string) $interval));
        $interval_count = max(1, (int) $interval_count);
        $type = match($interval) {
            'daily', 'day', 'days' => 'days',
            'weekly', 'week', 'weeks' => 'days',
            'monthly', 'month', 'months' => 'months',
            'quarterly' => 'months',
            'biannually' => 'months',
            'yearly', 'annual', 'year', 'years' => 'months',
            default => 'months',
        };
        $frequency = match($interval) {
            'daily', 'day', 'days' => $interval_count,
            'weekly', 'week', 'weeks' => $interval_count * 7,
            'monthly', 'month', 'months' => $interval_count,
            'quarterly' => $interval_count * 3,
            'biannually' => $interval_count * 6,
            'yearly', 'annual', 'year', 'years' => $interval_count * 12,
            default => $interval_count,
        };
        return ['frequency' => $frequency, 'frequency_type' => $type];
    }

    public function getOrCreatePlan($plan_config)
    {
        if (!empty($plan_config['mercadopago_plan_id'])) {
            return ['status' => 'success', 'plan_id' => $plan_config['mercadopago_plan_id'], 'created' => false];
        }
        $this->setAccessToken();
        $freq = $this->recurring_frequency($plan_config['interval'] ?? 'monthly', $plan_config['interval_count'] ?? 1);
        try {
            $client = new PreApprovalPlanClient();
            $plan = $client->create([
                'reason' => $plan_config['title'] ?? 'Recurring Plan',
                'auto_recurring' => [
                    'frequency' => $freq['frequency'],
                    'frequency_type' => $freq['frequency_type'],
                    'transaction_amount' => (float) $this->charge_amount($plan_config['price'] ?? $plan_config['amount'] ?? 0),
                    'currency_id' => $this->charge_currency(),
                ],
                'back_url' => $plan_config['back_url'] ?? '',
            ]);
            return ['status' => 'success', 'plan_id' => $plan->id, 'created' => true];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function charge_customer_recurring(array $args)
    {
        $this->setAccessToken();
        $plan_config = $args['plan_config'] ?? [
            'title' => $args['title'] ?? 'Monthly Donation',
            'price' => $args['amount'],
            'interval' => $args['recurring_interval'] ?? 'monthly',
            'interval_count' => $args['recurring_interval_count'] ?? 1,
            'back_url' => $args['ipn_url'],
        ];
        $plan = $this->getOrCreatePlan($plan_config);
        if ($plan['status'] !== 'success') {
            throw new \RuntimeException('MercadoPago plan error: ' . ($plan['message'] ?? 'unknown'));
        }
        $freq = $this->recurring_frequency($args['recurring_interval'] ?? 'monthly', $args['recurring_interval_count'] ?? 1);
        try {
            $client = new PreApprovalClient();
            $preapproval = $client->create([
                'preapproval_plan_id' => $plan['plan_id'],
                'payer_email' => $args['email'] ?? '',
                'reason' => $args['description'] ?? $args['title'] ?? 'Subscription',
                'auto_recurring' => [
                    'frequency' => $freq['frequency'],
                    'frequency_type' => $freq['frequency_type'],
                    'transaction_amount' => (float) $this->charge_amount($args['amount']),
                    'currency_id' => $this->charge_currency(),
                ],
                'back_url' => $args['ipn_url'],
                'external_reference' => (string) ($args['order_id'] ?? ''),
            ]);
            session()->put('mercadopago_subscription_id', $preapproval->id);
            session()->put('mercadopago_order_id', $args['order_id']);
            $redirect = $preapproval->init_point ?? null;
            if ($redirect) {
                return redirect()->away($redirect);
            }
            throw new \RuntimeException('MercadoPago subscription approval URL missing.');
        } catch (MPApiException $e) {
            throw new \RuntimeException('MercadoPago subscription failed: ' . $e->getMessage());
        }
    }

    public function ipn_response_recurring(array $args = [])
    {
        $subscription_id = session()->pull('mercadopago_subscription_id');
        $order_id = session()->pull('mercadopago_order_id');
        $token = request()->get('preapproval_id') ?? $subscription_id;
        if (empty($token)) {
            return ['status' => 'failed', 'order_id' => $order_id];
        }
        $this->setAccessToken();
        try {
            $client = new PreApprovalClient();
            $preapproval = $client->get($token);
            if (in_array($preapproval->status ?? '', ['authorized', 'active'], true)) {
                return $this->verified_data([
                    'transaction_id' => $token,
                    'order_id' => $order_id,
                    'mercadopago_subscription_id' => $token,
                    'subscription_status' => $preapproval->status,
                    'is_recurring' => true,
                ]);
            }
        } catch (\Exception $e) {
            return ['status' => 'failed', 'order_id' => $order_id];
        }
        return ['status' => 'failed', 'order_id' => $order_id];
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        $this->setAccessToken();
        try {
            $client = new PreApprovalClient();
            $result = $client->update($subscription_id, ['status' => 'cancelled']);
            return ['status' => 'success', 'subscription_data' => (array) $result];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function pause_subscription($subscription_id)
    {
        $this->setAccessToken();
        try {
            $client = new PreApprovalClient();
            $result = $client->update($subscription_id, ['status' => 'paused']);
            return ['status' => 'success', 'subscription_data' => (array) $result];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function resume_subscription($subscription_id)
    {
        $this->setAccessToken();
        try {
            $client = new PreApprovalClient();
            $result = $client->update($subscription_id, ['status' => 'authorized']);
            return ['status' => 'success', 'subscription_data' => (array) $result];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function fetch_subscription($subscription_id)
    {
        $this->setAccessToken();
        try {
            $client = new PreApprovalClient();
            $result = $client->get($subscription_id);
            return ['status' => 'success', 'subscription_data' => (array) $result];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function supported_currency_list()
    {
        return ['BRL', 'ARS', 'BOB', 'CLF', 'CLP', 'COP', 'CRC', 'CUC', 'CUP', 'DOP', 'EUR', 'GTQ', 'HNL', 'MXN', 'NIO', 'PAB', 'PEN', 'PYG', 'USD', 'UYU', 'VEF', 'VES'];
    }

    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "BRL";
    }

    /* set app secret */
    public function setClientSecret($client_secret)
    {
        $this->client_secret = $client_secret;
        return $this;
    }

    /* get app secret */
    private function getClientSecret()
    {
        return $this->client_secret;
    }

    /* get app id */
    private function getClientId()
    {
        return $this->client_id;
    }

    /* set app id */
    public function setClientId($client_id)
    {
        $this->client_id = $client_id;
        return $this;
    }

    public function gateway_name()
    {
        return 'mercadopago';
    }
}