<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Resources\Preference;
use MercadoPago\Resources\Payment;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoPay extends PaymentGatewayBase
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