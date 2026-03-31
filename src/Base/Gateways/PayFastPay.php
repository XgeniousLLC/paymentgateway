<?php

namespace Xgenious\Paymentgateway\Base\Gateways;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Models\PaymentMeta;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Xgenious\Paymentgateway\Traits\ZarCurrencySupport;


class PayFastPay extends PaymentGatewayBase
{
    protected $merchant_id;
    protected $merchant_key;
    protected $passphrase;

    use PaymentEnvironment, CurrencySupport, ZarCurrencySupport;

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

    public function setPassphrase($passphrase)
    {
        $this->passphrase = $passphrase;
        return $this;
    }

    public function getPassphrase()
    {
        return $this->passphrase;
    }

    /**
     * @inheritDoc
     *
     * this payment gateway will not work without this package
     * @ https://github.com/kingflamez/laravelrave
     *
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->is_decimal($amount) ? $amount : number_format((float)$amount, 2, '.', '');
        }
        return $this->is_decimal($this->get_amount_in_zar($amount)) ? $this->get_amount_in_zar($amount) : number_format((float)$this->get_amount_in_zar($amount), 2, '.', '');
    }

    /**
     * @inheritDoc
     * @param array $args
     * @required param list
     * request
     *
     * @return array
     */
    public function ipn_response(array $args = [])
    {
        // Get raw POST data and parse it manually to preserve empty strings
        $rawPost = file_get_contents('php://input');

        // Parse the raw POST data manually to preserve empty strings
        parse_str($rawPost, $pfData);

        // Create signature verification data (include ALL values except signature)
        $signatureData = [];
        foreach ($pfData as $key => $value) {
            if ($key !== 'signature') {
                $signatureData[$key] = trim($value);
            }
        }


        // Step 1: Verify signature
        $signature = $this->generateSignature($signatureData);

        if ($signature !== $pfData['signature']) {

            return ['status' => 'failed', 'reason' => 'Invalid signature'];
        }

        // Step 2: Validate with PayFast
        $payfastHost = $this->getEnv() ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$payfastHost}/eng/query/validate");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawPost); // Use raw POST data for validation
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);


        if (strcasecmp($result, 'VALID') === 0 && $pfData['payment_status'] === 'COMPLETE') {

            Log::info('from payfast ipn response');
            Log::info($pfData['m_payment_id']);
            return $this->verified_data([
                'status'         => 'complete',
                'order_id'       => substr($pfData['m_payment_id'], 5, -5),
                'transaction_id' => $pfData['pf_payment_id'],
            ]);
        }

        return ['status' => 'failed'];
    }

    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {
        if ($this->charge_amount($args['amount']) > 500000) {
            return back()->with([
                'msg' => __('We could not process your request due to your amount being higher than the maximum.'),
                'type' => 'danger'
            ]);
        }

        $order_id = random_int(12345, 99999) . $args['order_id'] . random_int(12345, 99999);
        $amount = $this->charge_amount($args['amount']);

        $item_name = $args['description'];
        if (strlen($item_name) > 100) {
            $item_name = substr($item_name, 0, 97) . '...';
        }


        // Define parameters in the order specified by PayFast's attributes description
        $pfData = [
            'merchant_id'    => $this->getMerchantId(),
            'merchant_key'   => $this->getMerchantKey(),
            'return_url'     => $args['success_url'],
            'cancel_url'     => $args['cancel_url'],
            'notify_url'     => $args['ipn_url'],
            'name_first'     => $args['name'],
            'email_address'  => $args['email'],
            'm_payment_id'   => $order_id,
            'amount'         => number_format($amount, 2, '.', ''),
            'item_name'      => $item_name,
        ];

        // Generate signature
        $pfData['signature'] = $this->generateSignature($pfData);

        PaymentMeta::create([
            'gateway'    => 'payfast',
            'amount'     => $amount,
            'order_id'   => $order_id,
            'meta_data'  => json_encode($pfData),
            'session_id' => null,
            'type'       => $args['payment_type'],
            'track'      => Str::random(60),
        ]);

        $payfastUrl = $this->getEnv() ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process';

        return view('paymentgateway::payfast', [
            'pfData' => $pfData,
            'payfastUrl' => $payfastUrl
        ]);
    }

    protected function generateSignature(array $data)
    {
        // Remove signature if already present
        unset($data['signature']);

        // Build the parameter string exactly as PayFast expects
        $pfParamString = '';

        foreach ($data as $key => $val) {
            // Skip only the signature field
            if ($key === 'signature') {
                continue;
            }

            // Trim the value but keep empty strings
            $val = trim($val);

            // Add to parameter string (include empty values)
            $pfParamString .= $key . '=' . urlencode($val) . '&';
        }

        // Remove the last '&'
        $pfParamString = rtrim($pfParamString, '&');

        // Add passphrase if it exists
        if ($this->getPassphrase()) {
            $pfParamString .= '&passphrase=' . urlencode(trim($this->getPassphrase()));
        }

        $signature = md5($pfParamString);

        return $signature;
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ['ZAR'];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "ZAR";
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return 'payfast';
    }

    protected function setConfig()
    {
        Config::set([
            'payfast.testing' => $this->getEnv(), // Set to false when in production.
        ]);
    }
}
