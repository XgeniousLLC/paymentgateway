<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Illuminate\Support\Facades\Config;
use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class PaypalPay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle
{
    use PaymentEnvironment,CurrencySupport,ConvertUsdSupport;
    protected $client_id;
    protected $client_secret;
    protected $app_id;

    /* get app id */
    private function getAppId(){
        return  $this->app_id;
    }
    /* set app id */
    public function setAppId($app_id){
        $this->app_id = $app_id;
        return $this;
    }
    /* set app id */
    public function setClientId($client_id){
        $this->client_id = $client_id;
        return $this;
    }
    /* set app secret */
    public function setClientSecret($client_secret){
        $this->client_secret = $client_secret;
        return $this;
    }
    /* get app id */
    private function getClientId(){
        return  $this->client_id;
    }
    /* get secret key */
    private function getClientSecret(){
        return $this->client_secret;
    }
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
            return $this->is_decimal($amount) ? $amount : number_format((float)$amount,2,'.','');
        }
        return $this->is_decimal( $this->get_amount_in_usd($amount)) ? $this->get_amount_in_usd($amount) :number_format((float) $this->get_amount_in_usd($amount),2,'.','');
    }


    protected function getPaymentProvider($args){
        Config::set([
            'paypal.mode'    => $this->getEnv() ? 'sandbox' : 'live',
            'paypal.sandbox' => [
                'client_id'         => $this->getClientId(),
                'client_secret'     => $this->getClientSecret(),
                'app_id'            => $this->getAppId(),
            ],
            'paypal.live' => [
                'client_id'         => $this->getClientId(),
                'client_secret'     => $this->getClientSecret(),
                'app_id'            => $this->getAppId(),
            ],
            'paypal.payment_action' => 'Sale',
            'paypal.currency'       => $this->charge_currency(),
            'paypal.notify_url'     => $args['ipn_url'],
            'paypal.locale'         => app()->getLocale(),
            'paypal.validate_ssl'   => true,
        ]);
        $provider = new PayPalClient;
        $access_token = $provider->getAccessToken();

        abort_if(isset($access_token['type'])  && $access_token['type'] === 'error',405,$access_token['message'] ?? '');
        $provider->setAccessToken($access_token);
        return $provider;
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
       $provider = $this->getPaymentProvider($args);

        if($args['amount'] < 1){
            abort(500,__('minimum payable amount is 1'));
        }
        
        $order = $provider->createOrder([
            "intent"=> "CAPTURE",
            "purchase_units"=> [
                0 => [
                    "amount"=> [
                        "currency_code"=> $this->charge_currency(),
                        "value"=> number_format($this->charge_amount($args['amount']), 2, ".", "")
                    ]
                ]
            ],
            'application_context' => [
                'cancel_url' => $args['cancel_url'],
                'return_url' => $args['ipn_url']
            ]
        ]);

        // throw exception
        if(isset($order['error'])){
            abort(422, $order['error']['message']);
        }
        abort_if(isset($order['type'])  && $order['type'] === 'error',405,$order['message'] ?? '');
        $order_id = $order['id'];
        session()->put('paypal_order_id',$order_id);
        session()->put('paypal_ipn_url',$args['ipn_url']);
        session()->put('paypal_cancel_url',$args['cancel_url']);
        session()->put('script_order_id', $args['order_id']);
        $redirect_url = $order['links'][1]['href'];
        return redirect($redirect_url)->send();
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

        /** Get the payment ID before session clear **/
        $payment_id = session()->get('paypal_order_id');
        $script_order_id = session()->get('script_order_id');
        $paypal_ipn_url = session()->get('paypal_ipn_url');
        $paypal_cancel_url = session()->get('paypal_cancel_url');
        $request = request();
        /** clear the session payment ID **/
        session()->forget(['paypal_order_id','script_order_id','paypal_cancel_url','paypal_ipn_url']);

        if (empty($request->get('PayerID')) || empty($request->get('token'))) {
            return abort(404);
        }

        $provider = $this->getPaymentProvider(['ipn_url' => $paypal_ipn_url]);
        $order_details = $provider->showOrderDetails($payment_id);
      
        //dd($order_details);
    
      if (isset($order_details['status']) && $order_details['status'] === 'APPROVED') {
          return $this->verified_data([
                'status' => 'complete',
              'transaction_id' => $payment_id,
              'order_id' => $script_order_id
          ]);
      }
    
        return $this->verified_data([
            'status' => 'pending',
            'order_id' => $script_order_id
        ]);
    }

    public function recurring_cycle($interval, $interval_count = 1)
    {
        $interval = strtolower(trim((string) $interval));
        $interval_count = max(1, (int) $interval_count);
        $unit = match($interval) {
            'daily', 'day', 'days' => 'DAY',
            'weekly', 'week', 'weeks' => 'WEEK',
            'monthly', 'month', 'months' => 'MONTH',
            'quarterly' => 'MONTH',
            'biannually' => 'MONTH',
            'yearly', 'annual', 'year', 'years' => 'YEAR',
            default => 'MONTH',
        };
        $count = match($interval) {
            'quarterly' => $interval_count * 3,
            'biannually' => $interval_count * 6,
            default => $interval_count,
        };
        return ['interval_unit' => $unit, 'interval_count' => $count];
    }

    public function getOrCreatePlan($plan_config, $provider = null)
    {
        if (!empty($plan_config['paypal_plan_id'])) {
            return ['status' => 'success', 'plan_id' => $plan_config['paypal_plan_id'], 'created' => false];
        }
        $provider = $provider ?: $this->getPaymentProvider(['ipn_url' => '']);
        $cycle = $this->recurring_cycle($plan_config['interval'] ?? 'monthly', $plan_config['interval_count'] ?? 1);
        $amount = number_format((float) $this->charge_amount($plan_config['price'] ?? $plan_config['amount'] ?? 0), 2, '.', '');
        $product = $provider->createProduct([
            'name' => $plan_config['title'] ?? 'Recurring Plan',
            'type' => 'SERVICE',
        ]);
        if (empty($product['id'])) {
            return ['status' => 'failed', 'message' => $product['message'] ?? 'Failed to create PayPal product.'];
        }
        $plan = $provider->createPlan([
            'product_id' => $product['id'],
            'name' => $plan_config['title'] ?? 'Recurring Plan',
            'description' => $plan_config['description'] ?? ($plan_config['title'] ?? 'Recurring Plan'),
            'billing_cycles' => [[
                'frequency' => $cycle,
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => $amount, 'currency_code' => $this->charge_currency()]],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'payment_failure_threshold' => 3,
            ],
        ]);
        if (empty($plan['id'])) {
            return ['status' => 'failed', 'message' => $plan['message'] ?? 'Failed to create PayPal plan.'];
        }
        return ['status' => 'success', 'plan_id' => $plan['id'], 'created' => true, 'plan_data' => $plan];
    }

    public function charge_customer_recurring(array $args)
    {
        $provider = $this->getPaymentProvider($args);
        $plan_config = $args['plan_config'] ?? [
            'title' => $args['title'] ?? 'Monthly Donation',
            'price' => $args['amount'],
            'description' => $args['description'] ?? ($args['title'] ?? 'Monthly subscription'),
            'interval' => $args['recurring_interval'] ?? 'monthly',
            'interval_count' => $args['recurring_interval_count'] ?? 1,
        ];
        $plan = $this->getOrCreatePlan($plan_config, $provider);
        if ($plan['status'] !== 'success') {
            throw new \RuntimeException('PayPal plan error: ' . ($plan['message'] ?? 'unknown'));
        }
        $subscription = $provider->createSubscription([
            'plan_id' => $plan['plan_id'],
            'custom_id' => (string) ($args['order_id'] ?? ''),
            'application_context' => [
                'brand_name' => $args['title'] ?? 'Subscription',
                'cancel_url' => $args['cancel_url'],
                'return_url' => $args['ipn_url'],
                'user_action' => 'SUBSCRIBE_NOW',
            ],
        ]);
        if (empty($subscription['id'])) {
            throw new \RuntimeException('PayPal subscription error: ' . ($subscription['message'] ?? 'unknown'));
        }
        $approve = collect($subscription['links'] ?? [])->firstWhere('rel', 'approve');
        session()->put('paypal_subscription_id', $subscription['id']);
        session()->put('paypal_plan_id', $plan['plan_id']);
        session()->put('script_order_id', $args['order_id']);
        session()->put('paypal_is_subscription', true);
        if (!empty($approve['href'])) {
            return redirect($approve['href'])->send();
        }
        throw new \RuntimeException('PayPal subscription approval URL missing.');
    }

    public function ipn_response_recurring(array $args = [])
    {
        $subscription_id = session()->pull('paypal_subscription_id');
        $order_id = session()->pull('script_order_id');
        session()->forget(['paypal_plan_id', 'paypal_is_subscription']);
        $token = request()->get('subscription_id') ?? $subscription_id;
        if (empty($token)) {
            return ['status' => 'failed', 'order_id' => $order_id];
        }
        $provider = $this->getPaymentProvider(['ipn_url' => '']);
        $details = $provider->showSubscriptionDetails($token);
        if (in_array($details['status'] ?? '', ['ACTIVE', 'APPROVED', 'APPROVAL_PENDING'], true)) {
            return $this->verified_data([
                'transaction_id' => $token,
                'order_id' => $order_id,
                'paypal_subscription_id' => $token,
                'subscription_status' => $details['status'] ?? 'ACTIVE',
                'is_recurring' => true,
            ]);
        }
        return ['status' => 'failed', 'order_id' => $order_id];
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        try {
            $provider = $this->getPaymentProvider(['ipn_url' => '']);
            $provider->cancelSubscription($subscription_id, 'Cancelled by donor');
            return ['status' => 'success', 'subscription_data' => ['id' => $subscription_id]];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function pause_subscription($subscription_id)
    {
        try {
            $provider = $this->getPaymentProvider(['ipn_url' => '']);
            $provider->suspendSubscription($subscription_id, 'Paused by donor');
            return ['status' => 'success', 'subscription_data' => ['id' => $subscription_id]];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function resume_subscription($subscription_id)
    {
        try {
            $provider = $this->getPaymentProvider(['ipn_url' => '']);
            $provider->activateSubscription($subscription_id, 'Resumed by donor');
            return ['status' => 'success', 'subscription_data' => ['id' => $subscription_id]];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function fetch_subscription($subscription_id)
    {
        try {
            $provider = $this->getPaymentProvider(['ipn_url' => '']);
            $details = $provider->showSubscriptionDetails($subscription_id);
            return ['status' => 'success', 'subscription_data' => $details];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * geteway_name();
     * return @string
     * */
    public function gateway_name(){
        return 'paypal';
    }
    /**
     * charge_currency();
     * return @string
     * */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $this->getCurrency();
        }
        return  "USD";
    }
    /**
     * supported_currency_list();
     * it will returl all of supported currency for the payment gateway
     * return array
     * */
    public function supported_currency_list(){
        return ['AUD','USD','EUR'];
        //return ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'INR', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];
    }
}
