<?php
namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Helpers\PaytmChecksum;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\IndianCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class PaytmPay extends PaymentGatewayBase
{
    use PaymentEnvironment, CurrencySupport, IndianCurrencySupport;

    protected $merchant_id;
    protected $merchant_key;
    protected $merchant_website;
    protected $channel;
    protected $industry_type;

    public function setMerchantId($merchant_id)
    {
        $this->merchant_id = $merchant_id;
        return $this;
    }

    public function getMerchantId()
    {
        return $this->merchant_id;
    }

    public function setMerchantKey($merchant_key)
    {
        $this->merchant_key = $merchant_key;
        return $this;
    }

    public function getMerchantKey()
    {
        return $this->merchant_key;
    }

    public function setMerchantWebsite($merchant_website)
    {
        $this->merchant_website = $merchant_website;
        return $this;
    }

    public function getMerchantWebsite()
    {
        return $this->merchant_website;
    }

    public function setChannel($channel)
    {
        $this->channel = $channel;
        return $this;
    }

    public function getChannel()
    {
        return $this->channel;
    }

    public function setIndustryType($industry_type)
    {
        $this->industry_type = $industry_type;
        return $this;
    }

    public function getIndustryType()
    {
        return $this->industry_type;
    }

    /**
     * charge_amount();
     * @required param list
     * $amount
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $amount;
        }
        return $this->get_amount_in_inr($amount);
    }

    /**
     * @required param list
     * $args['amount']
     * $args['description']
     * $args['item_name']
     * $args['ipn_url']
     * $args['cancel_url']
     * $args['payment_track']
     * return redirect url for paytm
     *
     * @throws \Exception
     */
    public function charge_customer($args)
    {
        $charge_amount = $this->charge_amount($args['amount']);
        $order_id = PaymentGatewayHelpers::wrapped_id($args['order_id']);
        $final_amount = number_format((float) $charge_amount, 2, '.', '');

        $paytmParams = [
            "body" => [
                "requestType"   => "Payment",
                "mid"           => $this->getMerchantId(),
                "websiteName"   => $this->getEnv() ? 'WEBSTAGING' : 'DEFAULT',
                "orderId"       => $order_id,
                "callbackUrl"   => $args['ipn_url'],
                "txnAmount"     => [
                    "value"     => $final_amount,
                    "currency"  => "INR",
                ],
                "userInfo"      => [
                    "custId"    => $args['email'] ?? "CUST_" . Str::random(10),
                ],
            ]
        ];

        $checksum = PaytmChecksum::generateSignature(
            json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES),
            $this->getMerchantKey()
        );

        $paytmParams["head"] = ["signature" => $checksum];
        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

        // For Staging
        $url = $this->base_url() . "/theia/api/v1/initiateTransaction?mid=" . $this->getMerchantId() . "&orderId=" . $order_id;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->withBody($post_data)
            ->post($url);

        $result = $response->object();

        if (property_exists($result->head, 'signature')) {
            if (!property_exists($result->body, 'txnToken')) {
                abort(500, 'txnToken not found');
            }

            $bladeData = [
                'host' => $this->base_url(),
                'txnToken' => $result->body?->txnToken,
                'order_id' => $order_id,
                'amount' => $final_amount,
                'success_url' => $args['success_url'],
                'cancel_url' => $args['cancel_url'],
                'merchant_id' => $this->getMerchantId()
            ];

            return view('paymentgateway::paytm', compact('bladeData')); // build view file for js checkout
        } else {
            abort(500, $result->body?->resultInfo?->resultMsg);
        }
    }

    /**
     * Verify payment status using Paytm's official API
     */
    private function verifyPaymentStatus($order_id, $checksum_hash = null)
    {
        $paytmParams = [
            "body" => [
                "mid" => $this->getMerchantId(),
                "orderId" => $order_id,
            ]
        ];

        $checksum = PaytmChecksum::generateSignature(
            json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES),
            $this->getMerchantKey()
        );

        $paytmParams["head"] = ["signature" => $checksum];
        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

        $url = $this->base_url() . "/v3/order/status";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->withBody($post_data)
            ->post($url);

        return $response->object();
    }

    /**
     * Validate checksum from Paytm callback
     */
    private function validateChecksum($parameters, $paytm_checksum)
    {
        return PaytmChecksum::verifySignature($parameters, $this->getMerchantKey(), $paytm_checksum);
    }

    /**
     * @required param list
     * $args['request']
     * $args['cancel_url']
     * $args['success_url']
     *
     * return @void
     */
    public function ipn_response($args = [])
    {
        $post_data = request()->all();

        // Get checksum from the request
        $paytm_checksum = isset($post_data['CHECKSUMHASH']) ? $post_data['CHECKSUMHASH'] : '';

        // Remove checksum from post data for validation
        unset($post_data['CHECKSUMHASH']);

        $order_id = $post_data['ORDERID'] ?? '';

        if (empty($order_id)) {
            return ['status' => 'failed', 'message' => 'Order ID not found'];
        }

        // Validate checksum
        $is_checksum_valid = $this->validateChecksum($post_data, $paytm_checksum);

        if (!$is_checksum_valid) {
            return ['status' => 'failed', 'message' => 'Checksum validation failed'];
        }

        // Verify payment status from Paytm
        $verify_status = $this->verifyPaymentStatus($order_id, $paytm_checksum);

        if (!$verify_status) {
            return ['status' => 'failed', 'message' => 'Unable to verify payment status'];
        }

        // Check if payment was successful
        if (isset($verify_status->body->resultInfo->resultStatus) &&
            $verify_status->body->resultInfo->resultStatus === 'TXN_SUCCESS') {

            return $this->verified_data([
                'transaction_id' => $verify_status->body->txnId ?? ($post_data['TXNID'] ?? ''),
                'order_id' => substr($order_id, 5, -5), // Remove wrapper
                'amount' => $verify_status->body->txnAmount ?? ($post_data['TXNAMOUNT'] ?? ''),
                'currency' => 'INR',
                'payment_status' => $verify_status->body->resultInfo->resultStatus,
                'payment_method' => $post_data['PAYMENTMODE'] ?? '',
                'bank_transaction_id' => $post_data['BANKTXNID'] ?? '',
                'gateway_name' => $this->gateway_name(),
                'raw_response' => $verify_status
            ]);
        }

        // Handle failed transactions
        $failure_reason = $verify_status->body->resultInfo->resultMsg ?? 'Transaction failed';

        return [
            'status' => 'failed',
            'message' => $failure_reason,
            'order_id' => substr($order_id, 5, -5),
            'raw_response' => $verify_status
        ];
    }

    /**
     * Handle webhook/callback from Paytm (alternative method)
     */
    public function handleCallback($request_data = null)
    {
        $post_data = $request_data ?? request()->all();

        // Get checksum from the request
        $paytm_checksum = isset($post_data['CHECKSUMHASH']) ? $post_data['CHECKSUMHASH'] : '';

        // Create array for checksum validation (exclude CHECKSUMHASH)
        $checksum_data = $post_data;
        unset($checksum_data['CHECKSUMHASH']);

        // Validate checksum
        $is_checksum_valid = $this->validateChecksum($checksum_data, $paytm_checksum);

        if (!$is_checksum_valid) {
            return [
                'status' => 'failed',
                'message' => 'Checksum validation failed'
            ];
        }

        $order_id = $post_data['ORDERID'] ?? '';
        $txn_status = $post_data['STATUS'] ?? '';
        $txn_id = $post_data['TXNID'] ?? '';

        if ($txn_status === 'TXN_SUCCESS') {
            return $this->verified_data([
                'transaction_id' => $txn_id,
                'order_id' => substr($order_id, 5, -5),
                'amount' => $post_data['TXNAMOUNT'] ?? '',
                'currency' => 'INR',
                'payment_status' => $txn_status,
                'payment_method' => $post_data['PAYMENTMODE'] ?? '',
                'bank_transaction_id' => $post_data['BANKTXNID'] ?? '',
                'gateway_name' => $this->gateway_name(),
                'raw_response' => $post_data
            ]);
        }

        return [
            'status' => 'failed',
            'message' => $post_data['RESPMSG'] ?? 'Transaction failed',
            'order_id' => substr($order_id, 5, -5),
            'raw_response' => $post_data
        ];
    }

    /**
     * gateway_name();
     * return @string
     */
    public function gateway_name()
    {
        return 'paytm';
    }

    /**
     * charge_currency();
     * return @string
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "INR";
    }

    /**
     * supported_currency_list();
     * it will return all of supported currency for the payment gateway
     * return array
     */
    public function supported_currency_list()
    {
        return ['INR'];
    }

    public function base_url()
    {
        $url = $this->getEnv() ? "-stage" : "";
        return 'https://securegw' . $url . '.paytm.in';
    }
}