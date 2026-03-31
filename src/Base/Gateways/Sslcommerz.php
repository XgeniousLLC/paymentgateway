<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Billplz\Laravel\Billplz;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Traits\BangladeshiCurrencySupport;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\MyanmarCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Billplz\Signature;
use Illuminate\Support\Str;

class Sslcommerz extends PaymentGatewayBase
{
    use CurrencySupport,BangladeshiCurrencySupport,PaymentEnvironment;
    public $store_id;
    public $store_passwd;


    public function getStoreId(){
        return $this->store_id;
    }
    public function setStoreId($store_id){
        $this->store_id = $store_id;
        return $this;
    }

    public function getStorePasswd(){
        return $this->store_passwd;
    }
    public function setStorePasswd($store_passwd){
        $this->store_passwd = $store_passwd;
        return $this;
    }

    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $this->is_decimal($amount) ? $amount : number_format((float)$amount,2,'.','');
        }
        return $this->is_decimal( $this->get_amount_in_bdt($amount)) ? $this->get_amount_in_bdt($amount) :number_format((float) $this->get_amount_in_bdt($amount),2,'.','');
    }

    public function ipn_response(array $args = [])
    {
        $request = request();
        $status = $request->status; //VALID
        $order_id = $request->tran_id;
        $value_a = $request->value_a;
        $transaction_id = $request->val_id;

        $response =  Http::get($this->getBaseUrl().'/validator/api/validationserverAPI.php',[
            'val_id'=>$transaction_id,
            'store_id'=> $this->getStoreId(),
            'store_passwd'=> $this->getStorePasswd(),
            'format'=> 'json'
        ]);
        $result = $response->object();
        if($result?->status === 'VALID' or $result?->status==="VALIDATED"){
            return $this->verified_data([
                    'status' => 'complete',
                    'transaction_id' => $transaction_id,
                    'order_id' => PaymentGatewayHelpers::unwrapped_id($order_id),
                'type' => $value_a ,
            ]);
        }

        return  ['status' => 'failed','order_id' => PaymentGatewayHelpers::unwrapped_id($order_id),'type' => $value_a];
    }
    public function charge_customer(array $args)
    {
        // call charge_customer method for any conversion before sending to payment gateway
        $amount = $this->charge_amount($args['amount']);
        // now change default currency to bdt because we've already converted the amount
        $this->setCurrency('BDT');
        $params = [
            'store_id' => $this->getStoreId(),
            'store_passwd' => $this->getStorePasswd(),
            'total_amount' => $amount ,//must be in decimal
            'currency' => $this->getCurrency(),
            'tran_id' => PaymentGatewayHelpers::wrapped_id($args['order_id']),
            'product_category' => $args['payment_type'],
            'success_url' => $args['ipn_url'],
            'fail_url' => $args['cancel_url'],
            'cancel_url' => $args['cancel_url'],
            'ipn_url' => $args['ipn_url'], //need to be a post route
            'cus_name' => $args['name'],
            'cus_email' => $args['email'],
            'value_a' => $args['payment_type'],
            'shipping_method' => 'NO',
            'cus_add1' => ' ',
            'cus_city' => ' ',
            'cus_country' => ' ',
            'cus_phone' => ' ',
            'product_name' => $args['title'],
            'product_profile' => 'general'
        ];


       $response =  Http::asForm()
       ->post($this->getBaseUrl().'/gwprocess/v4/api.php',$params);
       $result = $response->object();

       if ($result->status === 'FAILED'){
           abort(400,$result->failedreason);
       }
       return redirect()->away($result->redirectGatewayURL);
    }

    public function supported_currency_list()
    {
        return  ['BDT'];
    }

    public function charge_currency()
    {
        return 'BDT';
    }

    public function gateway_name()
    {
        return 'sslcommerz';
    }

    private function getBaseUrl()
    {
        $prefix = $this->getEnv() ? 'sandbox' : 'securepay';
        return 'https://'.$prefix.'.sslcommerz.com';
    }

}
