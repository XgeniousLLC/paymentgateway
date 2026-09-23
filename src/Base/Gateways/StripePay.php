<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use  Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\ConnectSupport;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Stripe\Charge;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Checkout\Session;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;

class StripePay extends PaymentGatewayBase implements RecurringSupport, SubscriptionLifecycle, ConnectSupport
{

    use PaymentEnvironment,CurrencySupport;

    protected $secret_key;
    protected $public_key;
    protected $webhook_secret;
    protected $recurring_interval = 'month';
    protected $recurring_interval_count = 1;
    protected $destination_account_id;
    protected $application_fee_amount;

   public function setSecretKey($secret_key){
       $this->secret_key = $secret_key;
       return $this;
   }
   private function getSecretKey(){
       return $this->secret_key;
   }
    public function setPublicKey($public_key){
       $this->public_key = $public_key;
       return $this;
    }
    private function getPublicKey(){
       return $this->public_key;
    }
    public function setWebhookSecret($webhook_secret){
        $this->webhook_secret = $webhook_secret;
        return $this;
    }
    private function getWebhookSecret(){
        return $this->webhook_secret;
    }
    public function setRecurringInterval($interval, $interval_count = 1){
        $this->recurring_interval = $this->normalize_interval($interval);
        $this->recurring_interval_count = max(1, (int) $interval_count);
        return $this;
    }
    public function setDestinationAccountId($account_id){
        $this->destination_account_id = $account_id;
        return $this;
    }
    private function getDestinationAccountId(){
        return $this->destination_account_id;
    }
    public function setApplicationFeeAmount($amount){
        $this->application_fee_amount = $amount;
        return $this;
    }
    private function getApplicationFeeAmount(){
        return $this->application_fee_amount;
    }

    public function normalize_interval($interval){
        $interval = strtolower(trim((string) $interval));
        return match($interval){
            'daily', 'day', 'days' => 'day',
            'weekly', 'week', 'weeks' => 'week',
            'monthly', 'month', 'months' => 'month',
            'yearly', 'annual', 'year', 'years' => 'year',
            default => 'month',
        };
    }

    private function stripe_client(){
        return new StripeClient($this->getSecretKey());
    }


    /**
     * this payment gateway will not work without this package
     * @https://github.com/stripe/stripe-php
     * @since .0.01
     * */
    public function charge_amount($amount)
    {
        $return_amount = $amount;
        if (in_array($this->getCurrency(), $this->supported_currency_list(), true)){
            if(in_array($this->getCurrency(), $this->zero_decimal_currencies())){
                return $return_amount;
            }
            return $amount * 100;
        }
    }
    private function zero_decimal_currencies(){
        return [
            'BIF','CLP','DJF','GNF','JPY', 'KMF','KRW', 'MGA', 'PYG','RWF','UGX','VND','VUV', 'XAF','XOF', 'XPF'
        ];
    }

    /**
     *
     * @param array $args
     * required param list
     *
     * @return string[]
     * @throws \Stripe\Exception\ApiErrorException
     * @since 0.0.1
     */
    public function ipn_response(array $args = []) : array
    {
        $stripe_session_id = session()->get('stripe_session_id');
        session()->forget('stripe_session_id');
        $stripe_order_id = session()->get('stripe_order_id');
        session()->forget('stripe_order_id');

        $stripe = new StripeClient($this->getSecretKey());
        $response = $stripe->checkout->sessions->retrieve($stripe_session_id, []);
        $payment_intent = $response['payment_intent'] ?? '';
        $payment_status = $response['payment_status'] ?? '';

        $capture = $stripe->paymentIntents->retrieve($payment_intent);
        if (!empty($payment_status) && $payment_status === 'paid' && $capture->status === 'succeeded') {
            $transaction_id = $payment_intent;
            if (!empty($transaction_id)) {
                return $this->verified_data([
                    'transaction_id' => $transaction_id,
                    'order_id' => $stripe_order_id
                ]);
            }
        }

        return ['status' => 'failed','order_id' => $stripe_order_id];
    }

    /**
     *
     * @param array $args
     * required param list
     *
     * product_name
     * amount
     * description
     * ipn_url
     * cancel_url
     * order_id
     *
     * @return array
     * @throws \Stripe\Exception\ApiErrorException
     * @since 0.0.1
     */
    public function charge_customer(array $args)
    {
       return $this->stripe_view($args);
    }

    public function stripe_view($args, $is_subscription = false){
        return view('paymentgateway::stripe', ['stripe_data' => array_merge($args,[
            'public_key' => $this->getPublicKey(),
            'currency' => $this->getCurrency(),
            'secret_key' => base64_encode($this->getSecretKey()),
            'charge_amount' => ceil($this->charge_amount($args['amount'])),
            'is_subscription' => $is_subscription || (($args['payment_type'] ?? '') === 'monthly'),
            'recurring_interval' => $args['recurring_interval'] ?? $this->recurring_interval,
            'recurring_interval_count' => $args['recurring_interval_count'] ?? $this->recurring_interval_count,
            'destination_account_id' => $args['destination_account_id'] ?? $this->getDestinationAccountId(),
            'application_fee_amount' => $args['application_fee_amount'] ?? $this->getApplicationFeeAmount(),
        ])]);
    }

    public function charge_customer_from_controller(array $args){
        Stripe::setApiKey(base64_decode($args['secret_key']));

         $payment_types = ['card'];

        if( strtolower($args['currency']) === "myr" ){
            $payment_types[] = 'fpx';
        }

        $is_subscription = !empty($args['is_subscription']) || (($args['payment_type'] ?? '') === 'monthly');
        $mode = $is_subscription ? 'subscription' : 'payment';

        if ($is_subscription) {
            $interval = $this->normalize_interval($args['recurring_interval'] ?? $this->recurring_interval);
            $interval_count = max(1, (int) ($args['recurring_interval_count'] ?? $this->recurring_interval_count));
            $line_item = [
                'price_data' => [
                    'currency' => $args['currency'],
                    'product_data' => [
                        'name' => $args['title'],
                        'description' => $args['description']
                    ],
                    'unit_amount' => $args['charge_amount'],
                    'recurring' => [
                        'interval' => $interval,
                        'interval_count' => $interval_count,
                    ],
                ],
                'quantity' => 1
            ];
        } else {
            $line_item = [
                'price_data' => [
                    'currency' => $args['currency'],
                    'product_data' => [
                        'name' => $args['title'],
                        'description' => $args['description']
                    ],
                    'unit_amount' => $args['charge_amount'],
                ],
                'quantity' => 1
            ];
        }

        $session_data = [
            'payment_method_types' => $payment_types,
            'line_items' => [$line_item],
            'mode' => $mode,
            'success_url' => $args['ipn_url'],
            'cancel_url' => $args['cancel_url'],
            'metadata' => [
                'order_id' => $args['order_id'],
                'track' => $args['track'] ?? '',
                'payment_type' => $args['payment_type'] ?? '',
            ],
        ];

        $destination = $args['destination_account_id'] ?? $this->getDestinationAccountId();
        $fee = $args['application_fee_amount'] ?? $this->getApplicationFeeAmount();
        $payment_intent_data = [];
        if (!empty($destination)) {
            $transfer = ['destination' => $destination];
            if (!empty($fee)) {
                $transfer['amount'] = (int) $fee;
            }
            $payment_intent_data['transfer_data'] = $transfer;
        } elseif (!empty($fee)) {
            $payment_intent_data['application_fee_amount'] = (int) $fee;
        }
        if (!empty($payment_intent_data)) {
            $session_data['payment_intent_data'] = $payment_intent_data;
        }

        $session = Session::create($session_data);

        session()->put('stripe_session_id', $session->id);
        session()->put('stripe_order_id', $args['order_id']);
        session()->put('stripe_is_subscription', $is_subscription);

        return ['id' => $session->id];
    }

    public function charge_customer_recurring(array $args)
    {
        $args['payment_type'] = $args['payment_type'] ?? 'monthly';
        if (!empty($args['recurring_interval'])) {
            $this->setRecurringInterval($args['recurring_interval'], $args['recurring_interval_count'] ?? 1);
        }
        return $this->stripe_view($args, true);
    }

    public function charge_customer_recurring_from_controller(array $args){
        $args['is_subscription'] = true;
        return $this->charge_customer_from_controller($args);
    }

    public function ipn_response_recurring(array $args = [])
    {
        $stripe_session_id = session()->get('stripe_session_id');
        session()->forget('stripe_session_id');
        $stripe_order_id = session()->get('stripe_order_id');
        session()->forget('stripe_order_id');
        session()->forget('stripe_is_subscription');

        $stripe = $this->stripe_client();
        $response = $stripe->checkout->sessions->retrieve($stripe_session_id, []);
        $subscription_id = $response['subscription'] ?? '';
        $payment_status = $response['payment_status'] ?? '';
        $mode = $response['mode'] ?? '';

        if ($mode === 'subscription' && !empty($subscription_id) && in_array($payment_status, ['paid', 'no_payment_required'], true)) {
            $subscription = $stripe->subscriptions->retrieve($subscription_id, []);
            return $this->verified_data([
                'transaction_id' => $subscription_id,
                'order_id' => $stripe_order_id,
                'stripe_subscription_id' => $subscription_id,
                'stripe_customer_id' => $subscription['customer'] ?? ($response['customer'] ?? ''),
                'subscription_status' => $subscription['status'] ?? 'active',
                'is_recurring' => true,
            ]);
        }

        return ['status' => 'failed', 'order_id' => $stripe_order_id];
    }

    public function cancel_subscription($subscription_id, $at_period_end = true)
    {
        try {
            $stripe = $this->stripe_client();
            if ($at_period_end) {
                $result = $stripe->subscriptions->update($subscription_id, ['cancel_at_period_end' => true]);
            } else {
                $result = $stripe->subscriptions->cancel($subscription_id, []);
            }
            return ['status' => 'success', 'subscription_data' => $result->toArray()];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function pause_subscription($subscription_id)
    {
        try {
            $stripe = $this->stripe_client();
            $result = $stripe->subscriptions->update($subscription_id, ['pause_collection' => ['behavior' => 'mark_uncollectible']]);
            return ['status' => 'success', 'subscription_data' => $result->toArray()];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function resume_subscription($subscription_id)
    {
        try {
            $stripe = $this->stripe_client();
            $result = $stripe->subscriptions->update($subscription_id, ['pause_collection' => '']);
            return ['status' => 'success', 'subscription_data' => $result->toArray()];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function fetch_subscription($subscription_id)
    {
        try {
            $stripe = $this->stripe_client();
            $result = $stripe->subscriptions->retrieve($subscription_id, []);
            return ['status' => 'success', 'subscription_data' => $result->toArray()];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function create_connect_account(array $args)
    {
        try {
            $stripe = $this->stripe_client();
            $account = $stripe->accounts->create([
                'type' => $args['type'] ?? 'express',
                'country' => $args['country'] ?? 'US',
                'email' => $args['email'] ?? null,
                'capabilities' => $args['capabilities'] ?? [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'metadata' => $args['metadata'] ?? [],
            ]);
            return ['status' => 'success', 'account_id' => $account->id, 'account_data' => $account->toArray()];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function create_account_onboarding_link($account_id, array $args = [])
    {
        try {
            $stripe = $this->stripe_client();
            $link = $stripe->accountLinks->create([
                'account' => $account_id,
                'refresh_url' => $args['refresh_url'] ?? '',
                'return_url' => $args['return_url'] ?? '',
                'type' => $args['type'] ?? 'account_onboarding',
            ]);
            return ['status' => 'success', 'url' => $link->url];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function construct_webhook_event($payload, $signature_header)
    {
        $secret = $this->getWebhookSecret();
        if (empty($secret) || empty($signature_header)) {
            return ['status' => 'failed', 'message' => 'webhook secret or signature missing'];
        }
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature_header, $secret);
            return ['status' => 'success', 'event' => $event];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * this will refund payment gateway charge currency
     * @since 0.0.1
     * */
    public function supported_currency_list() : array
    {
        return [
            'USD',
            'EUR',
            'INR',
            'IDR',
            'AUD',
            'SGD',
            'JPY',
            'GBP',
            'MYR',
            'PHP',
            'THB',
            'KRW',
            'NGN',
            'GHS',
            'BRL',
            'BIF',
            'CAD',
            'CDF',
            'CVE',
            'GHP',
            'GMD',
            'GNF',
            'KES',
            'LRD',
            'MWK',
            'MZN',
            'RWF',
            'SLL',
            'STD',
            'TZS',
            'UGX',
            'XAF',
            'XOF',
            'ZMK',
            'ZMW',
            'ZWD',
            'AED',
            'AFN',
            'ALL',
            'AMD',
            'ANG',
            'AOA',
            'ARS',
            'AWG',
            'AZN',
            'BAM',
            'BBD',
            'BDT',
            'BGN',
            'BMD',
            'BND',
            'BOB',
            'BSD',
            'BWP',
            'BZD',
            'CHF',
            'CNY',
            'CLP',
            'COP',
            'CRC',
            'CZK',
            'DJF',
            'DKK',
            'DOP',
            'DZD',
            'EGP',
            'ETB',
            'FJD',
            'FKP',
            'GEL',
            'GIP',
            'GTQ',
            'GYD',
            'HKD',
            'HNL',
            'HRK',
            'HTG',
            'HUF',
            'ILS',
            'ISK',
            'JMD',
            'KGS',
            'KHR',
            'KMF',
            'KYD',
            'KZT',
            'LAK',
            'LBP',
            'LKR',
            'LSL',
            'MAD',
            'MDL',
            'MGA',
            'MKD',
            'MMK',
            'MNT',
            'MOP',
            'MRO',
            'MUR',
            'MVR',
            'MXN',
            'NAD',
            'NIO',
            'NOK',
            'NPR',
            'NZD',
            'PAB',
            'PEN',
            'PGK',
            'PKR',
            'PLN',
            'PYG',
            'QAR',
            'RON',
            'RSD',
            'RUB',
            'SAR',
            'SBD',
            'SCR',
            'SEK',
            'SHP',
            'SOS',
            'SRD',
            'SZL',
            'TJS',
            'TRY',
            'TTD',
            'TWD',
            'UAH',
            'UYU',
            'UZS',
            'VND',
            'VUV',
            'WST',
            'XCD',
            'XPF',
            'YER',
            'ZAR'
        ];
    }
    /**
     * this will refund payment gateway charge currency
     * */
    public function charge_currency()
    {
        return $this->getCurrency();
    }
    /**
     * this will refund payment gateway name
     * */
    public function gateway_name() : string
    {
        return 'stripe';
    }
}
