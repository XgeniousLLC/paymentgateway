<?php

namespace Xgenious\Paymentgateway\Traits;

use Xgenious\Paymentgateway\Base\GlobalCurrency;

trait NigeriaCurrencySupport
{
    /**
     * get_amount_in_ngn()
     * @since 1.0.0
     * This function returns any amount in NGN based on the currency conversion rate set by the admin.
     * Paystack requires amounts in kobo (NGN * 100).
     */
    protected function get_amount_in_ngn($amount, $multiply = true)
    {
        if ($this->getCurrency() === 'NGN') {
            return $multiply ? $amount * 100 : $amount;
        }

        $payable_amount = $this->make_amount_in_ngn($amount, $this->getCurrency());

        if ($payable_amount < 1) {
            return $payable_amount . __('NGN amount is not supported by ' . $this->gateway_name());
        }

        return $multiply ? $payable_amount * 100 : $payable_amount;
    }

    /**
     * Convert amount to NGN currency based on conversion set by admin
     */
    private function make_amount_in_ngn($amount, $currency)
    {
        $output = 0;
        $all_currency = GlobalCurrency::script_currency_list();

        foreach ($all_currency as $cur => $symbol) {
            if ($cur === 'NGN') {
                continue;
            }

            if ($cur === $currency) {
                $exchange_rate = $this->getExchangeRate(); // Ensure this returns the correct rate to NGN
                $output = $amount * $exchange_rate;
            }
        }

        return $output;
    }
}
