<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class CinetPay extends PaymentGatewayBase
{
    use CurrencySupport, PaymentEnvironment, ConvertUsdSupport;

    protected $app_key;
    protected $site_id;

    // CinetPay v2 API base URL
    private const CHECKOUT_URL = 'https://api-checkout.cinetpay.com/v2/payment';
    private const VERIFY_URL   = 'https://api-checkout.cinetpay.com/v2/payment/check';

    public function setAppKey($app_key)
    {
        $this->app_key = $app_key;
        return $this;
    }

    public function getAppKey()
    {
        return $this->app_key;
    }

    public function setSiteId($site_id)
    {
        $this->site_id = $site_id;
        return $this;
    }

    public function getSiteId()
    {
        return $this->site_id;
    }

    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $amount;
        }
        return $this->get_amount_in_usd($amount);
    }

    /**
     * Handle CinetPay IPN/webhook callback.
     * CinetPay posts cpm_trans_id; we verify it via the check API.
     */
    public function ipn_response(array $args = [])
    {
        $id_transaction = request()->cpm_trans_id;

        if (empty($id_transaction)) {
            return ['status' => 'failed'];
        }

        try {
            $response = Http::post(self::VERIFY_URL, [
                'apikey'         => $this->getAppKey(),
                'site_id'        => $this->getSiteId(),
                'transaction_id' => $id_transaction,
            ])->json();

            $data = $response['data'] ?? [];

            if (($data['cpm_trans_status'] ?? '') === 'ACCEPTED') {
                $customData = json_decode($data['cpm_custom'] ?? '{}', true);

                return $this->verified_data([
                    'transaction_id' => $data['cpm_payid'] ?? $id_transaction,
                    'order_id'       => PaymentGatewayHelpers::unwrapped_id($customData['order_id'] ?? ''),
                    'payment_type'   => $customData['payment_type'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        return ['status' => 'failed'];
    }

    /**
     * Initiate a CinetPay payment via the v2 checkout API and redirect the customer.
     *
     * @param array $args  Keys: amount, order_id, description, ipn_url, success_url,
     *                     cancel_url, email, name, payment_type
     */
    public function charge_customer(array $args)
    {
        if ($this->charge_amount($args['amount']) < 100 && !in_array('USD', [$this->getCurrency()])) {
            abort(402, __('amount must be greater than 100'));
        }

        $order_id   = random_int(12345, 99999) . $args['order_id'] . random_int(12345, 99999);
        $custom_data = json_encode([
            'order_id'     => $order_id,
            'payment_type' => $args['payment_type'] ?? null,
        ]);

        $transaction_id = strtoupper(Str::random(20));

        try {
            $response = Http::post(self::CHECKOUT_URL, [
                'apikey'         => $this->getAppKey(),
                'site_id'        => $this->getSiteId(),
                'transaction_id' => $transaction_id,
                'amount'         => $this->charge_amount($args['amount']),
                'currency'       => $this->charge_currency(),
                'description'    => $args['description'],
                'return_url'     => $args['success_url'],
                'notify_url'     => $args['ipn_url'],
                'cancel_url'     => $args['cancel_url'],
                'customer_id'    => $custom_data,   // maps to cpm_custom in IPN/verify response
                'channels'       => 'ALL',
                'lang'           => 'fr',
            ])->json();
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        $code = $response['code'] ?? null;

        if ($code !== '201') {
            $message = $response['message'] ?? 'CinetPay payment initiation failed.';
            abort(502, $message);
        }

        $paymentUrl = $response['data']['payment_url'] ?? null;

        if (empty($paymentUrl)) {
            abort(502, 'CinetPay did not return a payment URL.');
        }

        return redirect($paymentUrl);
    }

    public function supported_currency_list()
    {
        return ['XOF', 'XAF', 'CDF', 'GNF', 'USD'];
    }

    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return 'USD';
    }

    public function gateway_name()
    {
        return 'cinetpay';
    }
}
