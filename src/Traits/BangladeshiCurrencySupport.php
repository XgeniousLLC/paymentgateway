<?php
namespace Xgenious\Paymentgateway\Traits;
use Xgenious\Paymentgateway\Base\GlobalCurrency;

trait BangladeshiCurrencySupport
{


    /**
     * get_amount_in_inr()
     * @since 1.0.0
     * this function return any amount to usd based on user given currency conversation value,
     * it will not work if admin did not give currency conversation rate
     * */
    protected function get_amount_in_bdt($amount){
        if ($this->getCurrency() === 'BDT'){
            return $amount;
        }
        $payable_amount = $this->make_amount_in_bdt($amount, $this->getCurrency());
        if ($payable_amount < 1) {
            return $payable_amount . __('BDT amount is not supported by '.$this->gateway_name());
        }
        return $payable_amount;
    }
    /**
     * convert amount to ngn currency base on conversation given by admin
     * */
    private function make_amount_in_bdt($amount, $currency)
    {
        $output = 0;
        $all_currency = GlobalCurrency::script_currency_list();
        foreach ($all_currency as $cur => $symbol) {
            if ($cur === 'BDT') {
                continue;
            }
            if ($cur == $currency) {
                $exchange_rate = $this->getExchangeRate();
                $output =  $amount * $exchange_rate;
            }
        }

        return $output;
    }

}
