<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Str;
use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class MidtransPay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle
{
    use PaymentEnvironment,CurrencySupport;

    protected $server_key;
    protected $client_key;
    /*
* charge_amount();
* @required param list
* $amount
*
*
* */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $amount;
        }
        return $this->get_amount_in_idr($amount);
    }

    /**
     * convert amount to idr currency base on conversation given by admin
     * */
    private  function make_amount_in_idr($amount, $currency)
    {
        $output = 0;
        $all_currency = GlobalCurrency::script_currency_list();
        foreach ($all_currency as $cur => $symbol) {
            if ($cur == 'IDR') {
                continue;
            }
            if ($cur == $currency) {
                $exchange_rate = $this->getExchangeRate();
                $output = $amount * $exchange_rate;
            }
        }

        return $output;
    }
    /**
     * get_amount_in_idr()
     * @since 1.0.0
     * this function return any amount to usd based on user given currency conversation value,
     * it will not work if admin did not give currency conversation rate
     * */
    protected  function get_amount_in_idr($amount){
        if ($this->getCurrency() === 'IDR'){
            return $amount;
        }
        $payable_amount = $this->make_amount_in_idr($amount, $this->getCurrency() );
        if ($payable_amount < 1) {
            return $payable_amount . __('amount is not supported by '.$this->gateway_name());
        }
        return $payable_amount;
    }


    /**
     * @required param list
     * $args['amount']
     * $args['description']
     * $args['item_name']
     * $args['ipn_url']
     * $args['cancel_url']
     * $args['payment_track']
     * return redirect url for paypal
     * */

    public function charge_customer($args)
    {
        $order_id =  random_int(12345,99999).$args['order_id'].random_int(12345,99999);
        $this->setConfig([
            'order_id' => $order_id,
            'ipn_url' => $args['ipn_url']
        ]);
        $params = array(
            'transaction_details' => array(
                'order_id' => $order_id,
                'gross_amount' => ceil($this->charge_amount($args['amount'])),
            ),
            "callbacks" => [
                "finish" => $args['ipn_url']
            ]
        );

        session()->put('midtrans_last_order_id',$order_id);
        try {
            $paymentUrl = \Midtrans\Snap::createTransaction($params)->redirect_url;
            return redirect()->away($paymentUrl);
        }
        catch (\Exception $e) {
            return $e->getMessage();
        }
    }


    /**
     * @required param list
     * $args['request']
     * $args['cancel_url']
     * $args['success_url']
     *
     * return @void
     * */
    public function ipn_response($args = []){

        $midtrans_last_order_id = session()->get('midtrans_last_order_id');
        session()->forget('midtrans_last_order_id');
        if (empty($midtrans_last_order_id)){
            abort(405,'midtrans order missing');
        }
        if ($midtrans_last_order_id !== request()->get('order_id')){
            abort(403);
        }
        $this->setConfig([
            'order_id' => $midtrans_last_order_id,
            'ipn_url' => $args['ipn_url'] ?? ''
        ]);

        $status = \Midtrans\Transaction::status($midtrans_last_order_id);
        $status_message = Str::contains($status->status_message,['Success']);
        if (in_array($status->transaction_status,  ['settlement','capture']) && $status->fraud_status === 'accept' && $status_message ){
            return $this->verified_data(['transaction_id' => $status->transaction_id,'order_id' => substr($midtrans_last_order_id,5,-5)]);
        }

        abort(404);
    }

    /**
     * geteway_name();
     * return @string
     * */
    public function gateway_name(){
        return 'midtrans';
    }
    /**
     * charge_currency();
     * return @string
     * */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "IDR";
    }
    /**
     * supported_currency_list();
     * it will returl all of supported currency for the payment gateway
     * return array
     * */
    public function supported_currency_list(){
        return ['IDR'];
    }

    /* set app id */
    public function setClientKey($client_key){
        $this->client_key = $client_key;
        return $this;
    }
    /* set app secret */
    public function setServerKey($server_key){
        $this->server_key = $server_key;
        return $this;
    }
    /* get app id */
    private function getClientKey(){
        return  $this->client_key;
    }
    /* get secret key */
    private function getServerKey(){
        return $this->server_key;
    }


    private function setConfig(array $args = [])
    {
        // Set your Merchant Server Key
        \Midtrans\Config::$serverKey = $this->getServerKey();
// Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
        \Midtrans\Config::$isProduction = !$this->getEnv();
// Set sanitization on (default)
        \Midtrans\Config::$isSanitized = true;
// Set 3DS transaction for credit card to true
        \Midtrans\Config::$is3ds = true;
        \Midtrans\Config::$overrideNotifUrl = $args['ipn_url'];
        \Midtrans\Config::$paymentIdempotencyKey = $args['order_id'];
    }

    public function recurring_schedule($interval, $interval_count = 1)
    {
        $interval = strtolower(trim((string) $interval));
        $interval_count = max(1, (int) $interval_count);
        $unit = match($interval) {
            'daily', 'day', 'days' => 'day',
            'weekly', 'week', 'weeks' => 'week',
            'monthly', 'month', 'months' => 'month',
            'quarterly' => 'month',
            'biannually' => 'month',
            'yearly', 'annual', 'year', 'years' => 'month',
            default => 'month',
        };
        $count = match($interval) {
            'quarterly' => $interval_count * 3,
            'biannually' => $interval_count * 6,
            'yearly', 'annual', 'year', 'years' => $interval_count * 12,
            default => $interval_count,
        };
        return ['interval_unit' => $unit, 'interval' => $count];
    }

    public function charge_customer_recurring(array $args)
    {
        $order_id = random_int(12345,99999).$args['order_id'].random_int(12345,99999);
        $this->setConfig(['order_id' => $order_id, 'ipn_url' => $args['ipn_url']]);
        $schedule = $this->recurring_schedule($args['recurring_interval'] ?? 'monthly', $args['recurring_interval_count'] ?? 1);
        $name = trim($args['name'] ?? '');
        $parts = explode(' ', $name, 2);
        try {
            $subscription = \Midtrans\CoreApi::createSubscription([
                'name' => 'SUB-' . $args['order_id'] . '-' . time(),
                'amount' => (string) ceil($this->charge_amount($args['amount'])),
                'currency' => 'IDR',
                'payment_type' => 'credit_card',
                'token' => $args['card_token'] ?? '',
                'schedule' => [
                    'interval' => $schedule['interval'],
                    'interval_unit' => $schedule['interval_unit'],
                    'max_interval' => 0,
                    'start_time' => gmdate('Y-m-d H:i:s O', time() + 60),
                ],
                'metadata' => ['order_id' => $args['order_id'], 'track' => $args['track'] ?? ''],
                'customer_details' => [
                    'first_name' => $parts[0] ?? 'Donor',
                    'last_name' => $parts[1] ?? '',
                    'email' => $args['email'] ?? '',
                    'phone' => $args['phone'] ?? '',
                ],
            ]);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
        session()->put('midtrans_subscription_id', $subscription->id ?? null);
        session()->put('midtrans_last_order_id', $order_id);
        $redirect = $subscription->redirect_url ?? null;
        if ($redirect) {
            return redirect()->away($redirect);
        }
        return redirect($args['ipn_url']);
    }

    public function ipn_response_recurring(array $args = [])
    {
        $subscription_id = session()->pull('midtrans_subscription_id');
        $midtrans_last_order_id = session()->get('midtrans_last_order_id');
        session()->forget('midtrans_last_order_id');
        if (empty($midtrans_last_order_id)) {
            abort(405, 'midtrans order missing');
        }
        $this->setConfig(['order_id' => $midtrans_last_order_id, 'ipn_url' => $args['ipn_url'] ?? '']);
        try {
            $subscription = \Midtrans\CoreApi::getSubscription($subscription_id);
        } catch (\Exception $e) {
            return ['status' => 'failed', 'order_id' => substr($midtrans_last_order_id, 5, -5)];
        }
        if (in_array($subscription->status ?? '', ['active', 'pending'], true)) {
            return $this->verified_data([
                'transaction_id' => $subscription_id,
                'order_id' => substr($midtrans_last_order_id, 5, -5),
                'midtrans_subscription_id' => $subscription_id,
                'subscription_status' => $subscription->status,
                'is_recurring' => true,
            ]);
        }
        return ['status' => 'failed', 'order_id' => substr($midtrans_last_order_id, 5, -5)];
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        $this->setConfig(['order_id' => $subscription_id, 'ipn_url' => '']);
        try {
            \Midtrans\CoreApi::disableSubscription($subscription_id);
            return ['status' => 'success', 'subscription_data' => ['id' => $subscription_id]];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function pause_subscription($subscription_id)
    {
        return $this->cancel_subscription($subscription_id, false);
    }

    public function resume_subscription($subscription_id)
    {
        $this->setConfig(['order_id' => $subscription_id, 'ipn_url' => '']);
        try {
            \Midtrans\CoreApi::enableSubscription($subscription_id);
            return ['status' => 'success', 'subscription_data' => ['id' => $subscription_id]];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function fetch_subscription($subscription_id)
    {
        $this->setConfig(['order_id' => $subscription_id, 'ipn_url' => '']);
        try {
            $subscription = \Midtrans\CoreApi::getSubscription($subscription_id);
            return ['status' => 'success', 'subscription_data' => (array) $subscription];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function verify_webhook_signature($payload, $signature)
    {
        if (empty($this->getServerKey()) || empty($signature)) {
            return false;
        }
        $data = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        if (empty($data['order_id']) || empty($data['status_code']) || empty($data['gross_amount'])) {
            return false;
        }
        $expected = hash('sha512', $data['order_id'] . $data['status_code'] . $data['gross_amount'] . $this->getServerKey());
        return hash_equals($expected, (string) $signature);
    }
}
