<?php

namespace Xgenious\Paymentgateway\Base\Gateways;
use Illuminate\Support\Facades\Config;
use Mollie\Laravel\Facades\Mollie;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class MolliePay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle
{
    protected $api_key;


    public function setApiKey($key){
        $this->api_key = $key;
        return $this;
    }

    public function getApiKey(){
        return $this->api_key;
    }

    use PaymentEnvironment,CurrencySupport,ConvertUsdSupport;
    /**
     * to work this payment gateway you must have this laravel package
     * https://github.com/mollie/laravel-mollie
     * */
    /**
     * @inheritDoc
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $amount;
        }
        return $this->get_amount_in_usd($amount);
    }

    /**
     * @inheritDoc
     * return array('status','transaction_id','order_id');
     */
    public function ipn_response(array $args = [])
    {
        $this->setConfig();
        $payment_id = session()->get('mollie_payment_id');
        $payment = Mollie::api()->payments->get($payment_id);
        session()->forget('mollie_payment_id');

        if ($payment->isPaid()) {
            return $this->verified_data([
                'status' => 'complete',
                'transaction_id' => $payment->id,
                'order_id' =>  substr($payment->metadata->order_id,5,-5)
            ]);
        }
        return ['status' => 'failed'];
    }

    /**
     * @inheritDoc
     * return array()
     */
    public function charge_customer(array $args)
    {
        $this->setConfig();
        $charge_amount = round($this->charge_amount($args['amount']), 2);
        $order_id =  random_int(12345,99999).$args['order_id'].random_int(12345,99999);
        try{
            $payment = Mollie::api()->payments->create([
                "amount" => [
                    "currency" => $this->charge_currency(),
                    "value" => number_format( $charge_amount, 2, '.', ''),//"10.00" // You must send the correct number of decimals, thus we enforce the use of strings
                ],
                "description" => $args['description'],
                "redirectUrl" => $args['ipn_url'],
                "metadata" => [
                    "order_id" => $order_id,
                    "track" => $args['track'],
                ],
            ]);

        }catch(\Exception $e){
            $msg = '';
            switch($e->getCode()){
                case(400):
                    $msg = __('Bad Request – The Mollie API was unable to understand your request. There might be an error in your syntax.');
                    break;
                case(401):
                    $msg = __('Unauthorized – Your request was not executed due to failed authentication. Check your API key.');
                    break;
                case(403):
                    $msg = __('Forbidden – You do not have access to the requested resource.');
                    break;
                case(404):
                    $msg = __('Not Found – The object referenced by your URL does not exist.');
                    break;
                case(405):
                    $msg = __('Method Not Allowed – You are trying to use an HTTP method that is not applicable on this URL or resource. Refer to the Allow header to see which methods the endpoint supports.');
                    break;
                case(409):
                    $msg = __('Conflict – You are making a duplicate API call that was probably a mistake (only in v2).');
                    break;
                case(410):
                    $msg = __('Gone – You are trying to access an object, which has previously been deleted (only in v2).');
                    break;
                case(415):
                    $msg = __('Unsupported Media Type – Your request’s encoding is not supported or is incorrectly understood. Please always use JSON.');
                    break;
                case(422):
                    $msg = $e->getMessage();
                    break;
                case(429):
                    $msg = __('Too Many Requests – Your request has hit a rate limit. Please wait for a bit and retry.');
                    break;
                case(500):
                    $msg = __('Internal Server Error – An internal server error occurred while processing your request. Our developers are notified automatically, but if you have any information on how you triggered the problem, please contact us.');
                    break;
                case(502):
                    $msg = __('Bad Gateway – The service is temporarily unavailable, either due to calamity or (planned) maintenance. Please retry the request at a later time.');
                    break;
                case(503):
                    $msg = __('Service Unavailable – The service is temporarily unavailable, either due to calamity or (planned) maintenance. Please retry the request at a later time.');
                    break;
                case(504):
                    $msg = __('Gateway Timeout – Your request is causing an unusually long process time.');
                    break;
                default:
                    $msg = $charge_amount.' '.config('paymentgateway.global_currency').' '. __('This amount is higher than the maximum.');
                    break;
            }
            abort(405,$msg);
        }

        $payment = Mollie::api()->payments->get($payment->id);

        session()->put('mollie_payment_id', $payment->id);
        return redirect($payment->getCheckoutUrl(), 303);
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ['AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HRK', 'HUF', 'ILS', 'ISK', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'RUB', 'SEK', 'SGD', 'THB', 'TWD', 'USD', 'ZAR'];
    }
    private function setConfig(){
        Config::set([
            'mollie.key' => $this->getApiKey()
        ]);
    }

    private function mollie_amount($amount)
    {
        return number_format(round($this->charge_amount($amount), 2), 2, '.', '');
    }

    public function recurring_interval_label($interval, $interval_count = 1)
    {
        $interval = strtolower(trim((string) $interval));
        $interval_count = max(1, (int) $interval_count);
        if ($interval_count > 1) {
            return $interval_count . ' months';
        }
        return match($interval) {
            'daily', 'day', 'days' => '1 day',
            'weekly', 'week', 'weeks' => '1 week',
            'monthly', 'month', 'months' => '1 month',
            'quarterly' => '3 months',
            'biannually' => '6 months',
            'yearly', 'annual', 'year', 'years' => '12 months',
            default => '1 month',
        };
    }

    public function getOrCreateCustomer($args)
    {
        $this->setConfig();
        if (!empty($args['mollie_customer_id'])) {
            return ['status' => 'success', 'customer_id' => $args['mollie_customer_id'], 'created' => false];
        }
        $customer = Mollie::api()->customers->create([
            'name' => $args['name'] ?? '',
            'email' => $args['email'] ?? '',
            'metadata' => ['order_id' => $args['order_id'] ?? '', 'track' => $args['track'] ?? ''],
        ]);
        return ['status' => 'success', 'customer_id' => $customer->id, 'created' => true];
    }

    public function charge_customer_recurring(array $args)
    {
        $this->setConfig();
        $customer = $this->getOrCreateCustomer($args);
        if ($customer['status'] !== 'success') {
            throw new \RuntimeException('Mollie customer error.');
        }
        $charge_amount = $this->mollie_amount($args['amount']);
        $order_id = random_int(12345,99999).$args['order_id'].random_int(12345,99999);
        // First payment establishes the mandate; the subscription then charges recurringly.
        $payment = Mollie::api()->payments->create([
            'amount' => ['currency' => $this->charge_currency(), 'value' => $charge_amount],
            'customerId' => $customer['customer_id'],
            'sequenceType' => 'first',
            'description' => $args['description'] ?? $args['title'] ?? '',
            'redirectUrl' => $args['ipn_url'],
            'metadata' => [
                'order_id' => $order_id,
                'track' => $args['track'] ?? '',
                'mollie_customer_id' => $customer['customer_id'],
                'subscription_amount' => $charge_amount,
                'subscription_interval' => $this->recurring_interval_label($args['recurring_interval'] ?? 'monthly', $args['recurring_interval_count'] ?? 1),
                'subscription_description' => $args['description'] ?? $args['title'] ?? '',
            ],
        ]);
        session()->put('mollie_payment_id', $payment->id);
        session()->put('mollie_pending_subscription', true);
        session()->put('mollie_order_id', $args['order_id']);
        return redirect($payment->getCheckoutUrl(), 303);
    }

    public function create_subscription_for_customer($customer_id, array $args)
    {
        $this->setConfig();
        $subscription = Mollie::api()->subscriptions->createForId($customer_id, [
            'amount' => ['currency' => $this->charge_currency(), 'value' => $this->mollie_amount($args['amount'])],
            'interval' => $this->recurring_interval_label($args['recurring_interval'] ?? 'monthly', $args['recurring_interval_count'] ?? 1),
            'description' => $args['description'] ?? $args['title'] ?? 'Subscription',
            'metadata' => ['order_id' => $args['order_id'] ?? '', 'track' => $args['track'] ?? ''],
        ]);
        return ['status' => 'success', 'subscription_data' => (array) $subscription];
    }

    public function ipn_response_recurring(array $args = [])
    {
        $this->setConfig();
        $payment_id = session()->get('mollie_payment_id');
        $payment = Mollie::api()->payments->get($payment_id);
        session()->forget('mollie_payment_id');
        if (!$payment->isPaid()) {
            return ['status' => 'failed'];
        }
        $metadata = (array) ($payment->metadata ?? []);
        $order_id = $metadata['order_id'] ?? '';
        $result = $this->verified_data([
            'status' => 'complete',
            'transaction_id' => $payment->id,
            'order_id' => substr($order_id, 5, -5),
            'is_recurring' => true,
        ]);
        // Chain: first payment done, create the subscription against the fresh mandate.
        if (!empty($metadata['mollie_customer_id']) && session()->pull('mollie_pending_subscription')) {
            try {
                $subscription = Mollie::api()->subscriptions->createForId($metadata['mollie_customer_id'], [
                    'amount' => ['currency' => $payment->amount->currency, 'value' => $metadata['subscription_amount']],
                    'interval' => $metadata['subscription_interval'],
                    'description' => $metadata['subscription_description'] ?? 'Subscription',
                    'metadata' => ['order_id' => $metadata['order_id'] ?? '', 'track' => $metadata['track'] ?? ''],
                ]);
                $result['mollie_subscription_id'] = $subscription->id;
                $result['mollie_customer_id'] = $metadata['mollie_customer_id'];
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Mollie subscription creation failed: ' . $e->getMessage());
            }
        }
        return $result;
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        $this->setConfig();
        try {
            $customer_id = request()->input('mollie_customer_id');
            if (empty($customer_id)) {
                return ['status' => 'failed', 'message' => 'Mollie cancel requires mollie_customer_id in the request.'];
            }
            $result = Mollie::api()->subscriptions->cancelForId($customer_id, $subscription_id);
            return ['status' => 'success', 'subscription_data' => (array) $result];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function pause_subscription($subscription_id)
    {
        return ['status' => 'failed', 'message' => 'Mollie subscriptions cannot be paused; cancel and recreate instead.'];
    }

    public function resume_subscription($subscription_id)
    {
        return ['status' => 'failed', 'message' => 'Mollie subscriptions cannot be resumed; create a new subscription instead.'];
    }

    public function fetch_subscription($subscription_id)
    {
        $this->setConfig();
        try {
            $customer_id = request()->input('mollie_customer_id');
            if (empty($customer_id)) {
                return ['status' => 'failed', 'message' => 'Mollie fetch requires mollie_customer_id in the request.'];
            }
            $result = Mollie::api()->subscriptions->getForId($customer_id, $subscription_id);
            return ['status' => 'success', 'subscription_data' => (array) $result];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
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
        return 'mollie';
    }
}
