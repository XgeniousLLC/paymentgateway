<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\IndianCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class PayuPay extends PaymentGatewayBase
{
    use PaymentEnvironment, CurrencySupport, IndianCurrencySupport;

    protected string $merchant_key  = '';
    protected string $merchant_salt = '';

    public function setMerchantKey(string $merchant_key): static
    {
        $this->merchant_key = $merchant_key;
        return $this;
    }

    public function getMerchantKey(): string
    {
        return $this->merchant_key;
    }

    public function setMerchantSalt(string $merchant_salt): static
    {
        $this->merchant_salt = $merchant_salt;
        return $this;
    }

    public function getMerchantSalt(): string
    {
        return $this->merchant_salt;
    }

    /**
     * Return the charge amount in the gateway's required format.
     * PayU expects a plain decimal string (e.g. "100.00").
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return number_format((float) $amount, 2, '.', '');
        }

        return number_format((float) $this->get_amount_in_inr($amount), 2, '.', '');
    }

    /**
     * Build the PayU payment form and return the auto-submitting blade view.
     *
     * Required $args keys:
     *   amount, title, description, ipn_url (surl), cancel_url (furl),
     *   order_id, track, email, name, payment_type
     */
    public function charge_customer(array $args)
    {
        $txnid       = PaymentGatewayHelpers::wrapped_id($args['order_id']);
        $amount      = $this->charge_amount($args['amount']);
        $productinfo = substr($args['title'], 0, 100);
        $firstname   = substr($args['name'] ?? 'Customer', 0, 60);
        $email       = $args['email'] ?? '';
        $udf1        = (string) $args['order_id'];   // original order ID, used on callback
        $udf2        = $args['payment_type'] ?? '';
        $udf3        = $args['track']        ?? '';

        $hash = $this->generateHash([
            'txnid'       => $txnid,
            'amount'      => $amount,
            'productinfo' => $productinfo,
            'firstname'   => $firstname,
            'email'       => $email,
            'udf1'        => $udf1,
            'udf2'        => $udf2,
            'udf3'        => $udf3,
        ]);

        $payuData = [
            'key'         => $this->getMerchantKey(),
            'txnid'       => $txnid,
            'amount'      => $amount,
            'productinfo' => $productinfo,
            'firstname'   => $firstname,
            'email'       => $email,
            'phone'       => '',
            'surl'        => $args['ipn_url'],
            'furl'        => $args['cancel_url'],
            'hash'        => $hash,
            'udf1'        => $udf1,
            'udf2'        => $udf2,
            'udf3'        => $udf3,
            'udf4'        => '',
            'udf5'        => '',
        ];

        $payuUrl = $this->getPaymentUrl();

        return view('paymentgateway::payu', compact('payuData', 'payuUrl'));
    }

    /**
     * Verify the PayU callback (POST to surl) and return a normalised result.
     *
     * PayU posts all transaction details plus a hash.  We recompute the reverse
     * hash and compare before trusting the status field.
     *
     * @return array{status: string, transaction_id?: string, order_id?: string}
     */
    public function ipn_response(array $args = []): array
    {
        $posted = request()->all();
        $status         = $posted['status']  ?? '';
        $hash_from_payu = $posted['hash']    ?? '';

        if (empty($hash_from_payu)) {
            return ['status' => 'failed', 'message' => 'Hash missing from PayU response'];
        }

        $computed_hash = $this->generateReverseHash($posted);

        if (! hash_equals($computed_hash, $hash_from_payu)) {
            return ['status' => 'failed', 'message' => 'Hash verification failed'];
        }

        if (strtolower($status) !== 'success') {
            return [
                'status'  => 'failed',
                'message' => $posted['error_Message'] ?? ($posted['field9'] ?? 'Payment failed'),
            ];
        }

        // udf1 holds the original (unwrapped) order_id stored at initiation
        $order_id = $posted['udf1'] ?? '';

        return $this->verified_data([
            'transaction_id' => $posted['mihpayid'] ?? ($posted['txnid'] ?? ''),
            'order_id'       => $order_id,
        ]);
    }

    /**
     * Forward hash for payment initiation.
     *
     * Sequence (PayU docs):
     *   key | txnid | amount | productinfo | firstname | email
     *   | udf1 | udf2 | udf3 | udf4 | udf5 | | | | | | SALT
     */
    protected function generateHash(array $params): string
    {
        $fields = [
            $this->getMerchantKey(),
            $params['txnid'],
            $params['amount'],
            $params['productinfo'],
            $params['firstname'],
            $params['email'],
            $params['udf1'] ?? '',
            $params['udf2'] ?? '',
            $params['udf3'] ?? '',
            $params['udf4'] ?? '',
            $params['udf5'] ?? '',
            '', '', '', '', '',          // udf6–udf10 always empty for standard integration
            $this->getMerchantSalt(),
        ];

        return strtolower(hash('sha512', implode('|', $fields)));
    }

    /**
     * Reverse hash for callback verification.
     *
     * Sequence (PayU docs reversed):
     *   SALT | status | udf10 | udf9 | udf8 | udf7 | udf6
     *   | udf5 | udf4 | udf3 | udf2 | udf1
     *   | email | firstname | productinfo | amount | txnid | key
     */
    protected function generateReverseHash(array $posted): string
    {
        $fields = [
            $this->getMerchantSalt(),
            $posted['status']      ?? '',
            $posted['udf10']       ?? '',
            $posted['udf9']        ?? '',
            $posted['udf8']        ?? '',
            $posted['udf7']        ?? '',
            $posted['udf6']        ?? '',
            $posted['udf5']        ?? '',
            $posted['udf4']        ?? '',
            $posted['udf3']        ?? '',
            $posted['udf2']        ?? '',
            $posted['udf1']        ?? '',
            $posted['email']       ?? '',
            $posted['firstname']   ?? '',
            $posted['productinfo'] ?? '',
            $posted['amount']      ?? '',
            $posted['txnid']       ?? '',
            $posted['key']         ?? '',
        ];

        return strtolower(hash('sha512', implode('|', $fields)));
    }


    public function getPaymentUrl(): string
    {
        return $this->getEnv()
            ? 'https://test.payu.in/_payment'
            : 'https://secure.payu.in/_payment';
    }

    public function supported_currency_list(): array
    {
        return ['INR'];
    }

    public function charge_currency(): string
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }

        return 'INR';
    }

    public function gateway_name(): string
    {
        return 'payu';
    }
}
