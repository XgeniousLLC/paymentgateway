<?php

namespace Xgenious\Paymentgateway\Base\Gateways;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Models\PaymentMeta;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Hash;

class CoinPayment extends PaymentGatewayBase
{
    use ConvertUsdSupport, CurrencySupport, PaymentEnvironment;

    protected $merchant;
    protected $ipn_id;
    protected $ipn_version='1.0';
    protected $allow_currencies;

    /**
     * @inheritDoc
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return  $amount;
        }
        return  $this->get_amount_in_usd($amount);
    }

    private function getMerchant()
    {
        return $this->merchant;
    }

    public function setMerchant(string $merchant){
         $this->merchant = $merchant;
        return $this;
    }

    private function getAllowCurrencies()
    {
        return $this->allow_currencies;
    }

    public function setAllowCurrencies(string $allow_currencies){
         $this->allow_currencies = $allow_currencies;
        return $this;
    }

    private function getIpnPin()
    {
        return $this->ipn_id;
    }

    public function setIpnPin(string $ipn_id){
         $this->ipn_id = $ipn_id;
        return $this;
    }

    private function getIpnVersion()
    {
        return $this->ipn_version;
    }

    public function setIpnVersion(string $ipn_version){
        $this->ipn_version = $ipn_version;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function ipn_response(array $args = [])
    {

        $request = request();
        $txn_id = $request->txn_id; // transaction id
        $status = $request->status;
        $status_text = $request->status_text;

        $merchant_id = $this->getMerchant();
        $ipn_secret = $this->getIpnPin(); //ipn secret

        if (!Request::header('hmac') || empty(Request::header('hmac'))) {
            abort(400, "No HMAC signature sent");
        }

        $merchant = Request::input('merchant', '');
        if (empty($merchant)) {
            abort(400, "No Merchant ID passed");
        }

        if ($merchant != $merchant_id) {
            abort(400, "Invalid Merchant ID");
        }

        $payment_meta_info =  PaymentMeta::where(["gateway" => "coinpayments","track" => $request->custom])->first();

        if(is_null($payment_meta_info)){
            abort(400, "payment meta not found");
        }

        if( $status === 100){
            return $this->verified_data([
                "status" => "complete",
                "transaction_id" => $txn_id,
                "order_id" => PaymentGatewayHelpers::unwrapped_id($payment_meta_info?->order_id),
                'payment_type' => $payment_meta_info?->type
            ]);
        }

        return ["status" => "failed"];
    }

    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {

        $final_amount = $this->charge_amount($args['amount']);
        $order_id = PaymentGatewayHelpers::wrapped_id($args['order_id']);
        $track = Str::random(60);

        $bladeData = [
            'host' => $this->base_url(),
            'amount' => $final_amount,
            'ipn_version' => $this->getIpnVersion(),
            'allow_currencies' => $this->getAllowCurrencies(),
            'currency' => $this->getCurrency(),
            'ipn_id' => $this->getIpnPin(),
            'custom' => $track,
            'merchant' => $this->getMerchant()
        ];

        // store meta data about the payment
        PaymentMeta::create([
            "gateway" => "coinpayments",
            "amount" => $final_amount,
            "order_id" => $order_id,
            "meta_data" => json_encode([
                "allow_currencies" => $this->getAllowCurrencies(),
                "ipn_version" => $this->getIpnVersion(),
            ]),
            "session_id" => 'no_session_key',
            "type" => $args["payment_type"],
            "track" => $track,
        ]);

        return view('paymentgateway::coin-payments',compact('bladeData','args')); // build view file for js checkout
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return [
            "USD",'LTCT','ZEN','ZEC','XVG','XMR','XEM','USDT.TRC20',
            'USDT.SOL','USDT','USDC.TRC20','USDC.SOL','TUSD.TRC20',
            'TRX','SYS','SOL','RVN','QTUM','PIVX','OMNI','MSOL.SOL',
            'MNDE.SOL','MATIC.POLY','MAID','MAD','JST.TRC20','ISLM.EVM',
            'FTN.BAHAMUT','FIRO','ETH','ETC','DOGE','DGB','DASH','CNHT.TRC20',
            'BXN','BUSD.TRC20','BTT.TRC20','BNB.BSC','BNB','VLX.Native','VLX',
            'LTC','BCH','BTC.LN','BTC'
        ];
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
        return "coinpayments";
    }

    private function base_url()
    {
        return "https://www.coinpayments.net/index.php";
    }


}
