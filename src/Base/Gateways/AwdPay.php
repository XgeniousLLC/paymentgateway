<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Billplz\Laravel\Billplz;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\MyanmarCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Billplz\Signature;
use Illuminate\Support\Str;

class AwdPay extends PaymentGatewayBase
{
    use CurrencySupport,ConvertUsdSupport,PaymentEnvironment;
    public $logo_url;
    public $private_key;


    public function getPrivateKey(){
        return $this->private_key;
    }
    public function setPrivateKey($private_key){
        $this->private_key = $private_key;
        return $this;
    }

    public function getLogoUrl(){
        return $this->logo_url;
    }
    public function setLogoUrl($logo_url){
        $this->logo_url = $logo_url;
        return $this;
    }

    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $amount * 100;
        }
        return $this->get_amount_in_usd($amount);
    }

    public function ipn_response(array $args = [])
    {
        $status = request()->status;
        $trxId = request()->trxId;
        $oder_id = request()->get('customIdentifier');

        if ($status && !empty($trxId)){
            //request for verify payment
            $respone = Http::withToken($this->getPrivateKey())
                ->post($this->getBaseUrl().'verify',[
                    'trxId' => $trxId,
                    'customIdentifier' => $oder_id,
                ]);
            $result = $respone->object();
            if($respone->ok() && strtolower($result->status) === 'success'){
                return $this->verified_data([
                    'status' => 'complete',
                    'transaction_id' => $trxId,
                    'order_id' => substr( $oder_id,5,-5) ,
                ]);
            }

        }
        return  ['status' => 'failed','order_id' => substr( $oder_id,5,-5) ];
    }
    public function charge_customer(array $args)
    {
        $charge_amount = round($this->charge_amount($args['amount']), 2);
        $order_id =  random_int(12345,99999).$args['order_id'].random_int(12345,99999);
        $respone = Http::withToken($this->getPrivateKey())
            ->post($this->getBaseUrl().'initiate',[
                'logo' => $this->getLogoUrl(),
                'amount' => $charge_amount,
                'currency' =>  $this->charge_currency(), //(ex: USD or EUR or XOF),
                'customIdentifier' => $order_id,
                'callbackUrl' => $args['ipn_url'], //post route
                'successUrl' => $args['success_url'],
                'failedUrl' => $args['cancel_url'],
                'test' => $this->getEnv()
            ]);
        $result = $respone->object();
        if($respone->ok() && $result->success){
            return redirect()->to($result->redirectUrl);
        }
        abort(501,__($result->message));
    }

    public function supported_currency_list()
    {
        return  ['USD','EUR','XOF'];
    }

    public function charge_currency()
    {
        return 'USD';
    }

    public function gateway_name()
    {
        return 'awdpay';
    }

    private function getBaseUrl()
    {
        return 'https://www.awdpay.com/api/checkout/v2/';
    }

}
