<?php

namespace Xgenious\Paymentgateway\Base\Gateways;
use Illuminate\Support\Facades\Http;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class FlutterwavePay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle
{

    protected $public_key;
    protected $secret_key;
    protected $secret_hash;

    use PaymentEnvironment,CurrencySupport,ConvertUsdSupport;

    public function setPublicKey($public_key){
        $this->public_key = $public_key;
        return $this;
    }
    public function getPublicKey(){
        return $this->public_key;
    }
    public function setSecretKey($secret_key){
        $this->secret_key = $secret_key;
        return $this;
    }
    public function getSecretKey(){
        return $this->secret_key;
    }
    public function setSecretHash($secret_hash){
        $this->secret_hash = $secret_hash;
        return $this;
    }
    public function getSecretHash(){
        return $this->secret_hash;
    }

    /**
     * @inheritDoc
     *  /**
     * this payment gateway will not work without this package
     * @ https://github.com/kingflamez/laravelrave
     * */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $amount;
        }
        return $this->get_amount_in_usd($amount);
    }

    /**
     * @inheritDoc
     * @param ['status','transaction_id','order_id' ]
     */
    public function ipn_response(array $args = [])
    {
        $response =  Http::withToken($this->getSecretKey())->get($this->getBaseUri() . "/transactions/" . request()->transaction_id . '/verify')->json();

        $status = $response['status'] ?? '';

        if ( $status === 'success'){
            $txRef = $response['data']['tx_ref'];
            $order_id = $response['data']['meta']['metavalue'];

            return $this->verified_data([
                'status' => 'complete',
                'transaction_id' => $txRef,
                'order_id' => substr( $order_id,5,-5) ,
            ]);
        }

        return ['status' => 'failed' ];
    }

    /**
     * @inheritDoc
     * @param ['amount','title','description' ,'ipn_url','order_id','track','cancel_url', 'success_url' ,'email','name','payment_type']
     */
    public function charge_customer(array $args)
    {
        if(!in_array($this->getCurrency(),["NGN","XAF"]) && $this->charge_amount($args['amount']) > 1000){
            abort(405,__('We could not process your request due to your amount is higher than the maximum.'));
        }


        $order_id =  random_int(12345,99999).$args['order_id'].random_int(12345,99999);
        // Enter the details of the payment
        $data = [
            'payment_options' => 'card,banktransfer',
            'amount' =>$this->charge_amount($args['amount']),
            'email' => $args['email'],
            'tx_ref' => $this->getTxRef(), // a unique number with uuid as a transaction reference
            'currency' => $this->charge_currency(),
            'redirect_url' => $args['ipn_url'],
            'customer' => [
                'email' => $args['email'],
                "name" => $args['name']
            ],
            "customizations" => [
                "title" => null,
                "description" => $args['description']
            ],
            'meta' =>  [
                'metaname' => 'order_id', 'metavalue' => $order_id,
            ]
        ];

        // Subscription checkout: attach the payment plan so Flutterwave renews automatically.
        if (!empty($args['payment_plan_id'])) {
            $data['payment_plan'] = $args['payment_plan_id'];
        }
        try {
            $payment = Http::withToken($this->getSecretKey())->post(
                $this->getBaseUri() . '/payments',
                $data
            )->json();
        }catch (\Exception $e){
            abort(500,$e->getMessage());
        }

        return redirect($payment['data']['link']);
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ['BIF', 'CAD', 'CDF', 'CVE', 'EUR', 'GBP', 'GHS', 'GMD', 'GNF', 'KES', 'LRD', 'MWK', 'MZN', 'NGN', 'RWF', 'SLL', 'STD', 'TZS', 'UGX', 'USD', 'XAF', 'XOF', 'ZMK', 'ZMW', 'ZWD'];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "USD";
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return 'flutterwaverave';
    }


    private function getBaseUri(){
        return 'https://api.flutterwave.com/v3';
    }
    private function getTxRef(){
        return 'flw_' . uniqid((string) time());
    }

    private function api_post($endpoint, array $payload)
    {
        return Http::withToken($this->getSecretKey())->post($this->getBaseUri() . $endpoint, $payload)->json();
    }

    private function api_get($endpoint)
    {
        return Http::withToken($this->getSecretKey())->get($this->getBaseUri() . $endpoint)->json();
    }

    private function api_put($endpoint, array $payload = [])
    {
        return Http::withToken($this->getSecretKey())->put($this->getBaseUri() . $endpoint, $payload)->json();
    }

    public function recurring_duration_label($interval, $interval_count = 1)
    {
        $interval = strtolower(trim((string) $interval));
        $interval_count = max(1, (int) $interval_count);
        if ($interval_count > 1) {
            return 'monthly';
        }
        return match($interval) {
            'daily', 'day', 'days' => 'daily',
            'weekly', 'week', 'weeks' => 'weekly',
            'monthly', 'month', 'months' => 'monthly',
            'quarterly' => 'quarterly',
            'biannually' => 'biannually',
            'yearly', 'annual', 'year', 'years' => 'yearly',
            default => 'monthly',
        };
    }

    public function getOrCreatePlan($plan_config)
    {
        if (!empty($plan_config['flutterwave_plan_id'])) {
            return ['status' => 'success', 'plan_id' => $plan_config['flutterwave_plan_id'], 'created' => false];
        }
        $response = $this->api_post('/payment-plans', [
            'amount' => $this->charge_amount($plan_config['price'] ?? $plan_config['amount'] ?? 0),
            'name' => $plan_config['title'] ?? 'Recurring Plan',
            'interval' => $this->recurring_duration_label($plan_config['interval'] ?? 'monthly', $plan_config['interval_count'] ?? 1),
            'duration' => 0,
            'currency' => $this->charge_currency(),
        ]);
        if (($response['status'] ?? '') === 'success' && !empty($response['data']['id'])) {
            return ['status' => 'success', 'plan_id' => $response['data']['id'], 'created' => true, 'plan_data' => $response['data']];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Failed to create Flutterwave plan.'];
    }

    public function charge_customer_recurring(array $args)
    {
        $plan_config = $args['plan_config'] ?? [
            'title' => $args['title'] ?? 'Monthly Donation',
            'price' => $args['amount'],
            'interval' => $args['recurring_interval'] ?? 'monthly',
            'interval_count' => $args['recurring_interval_count'] ?? 1,
        ];
        $plan = $this->getOrCreatePlan($plan_config);
        if ($plan['status'] !== 'success') {
            throw new \RuntimeException('Flutterwave plan error: ' . ($plan['message'] ?? 'unknown'));
        }
        $args['payment_plan_id'] = $plan['plan_id'];
        $args['is_subscription'] = true;
        return $this->charge_customer($args);
    }

    public function ipn_response_recurring(array $args = [])
    {
        $payment_data = $this->ipn_response($args);
        if (($payment_data['status'] ?? 'failed') === 'complete') {
            $payment_data['is_recurring'] = true;
        }
        return $payment_data;
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        $response = $this->api_put('/payment-plans/' . $subscription_id . '/cancel');
        if (($response['status'] ?? '') === 'success') {
            return ['status' => 'success', 'subscription_data' => $response['data'] ?? []];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Failed to cancel plan.'];
    }

    public function pause_subscription($subscription_id)
    {
        return $this->cancel_subscription($subscription_id, false);
    }

    public function resume_subscription($subscription_id)
    {
        return ['status' => 'failed', 'message' => 'Flutterwave plans cannot be resumed via API; create a new plan subscription.'];
    }

    public function fetch_subscription($subscription_id)
    {
        $response = $this->api_get('/payment-plans/' . $subscription_id);
        if (($response['status'] ?? '') === 'success') {
            return ['status' => 'success', 'subscription_data' => $response['data'] ?? []];
        }
        return ['status' => 'failed', 'message' => $response['message'] ?? 'Plan not found.'];
    }

    public function verify_webhook_signature($payload, $signature)
    {
        $secret_hash = method_exists($this, 'getSecretHash') ? $this->getSecretHash() : null;
        if (empty($secret_hash) || empty($signature)) {
            return false;
        }
        return hash_equals((string) $secret_hash, (string) $signature);
    }

}
