<?php

namespace Xgenious\Paymentgateway\Tests\Gateways;

use Xgenious\Paymentgateway\Base\ConnectSupport;
use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Tests\TestCase;
use Xgenious\Paymentgateway\Base\Gateways\StripePay;

class StripePayTest extends TestCase
{
    private function gateway(): StripePay
    {
        $stripe = new StripePay();
        $stripe->setSecretKey('sk_test_dummy');
        $stripe->setPublicKey('pk_test_dummy');
        $stripe->setCurrency('USD');
        $stripe->setEnv(true);
        return $stripe;
    }

    public function test_implements_recurring_lifecycle_and_connect_contracts()
    {
        $stripe = $this->gateway();
        $this->assertInstanceOf(RecurringSupport::class, $stripe);
        $this->assertInstanceOf(SubscriptionLifecycle::class, $stripe);
        $this->assertInstanceOf(ConnectSupport::class, $stripe);
    }

    public function test_normalize_interval_maps_common_aliases()
    {
        $stripe = $this->gateway();
        $this->assertEquals('month', $stripe->normalize_interval('monthly'));
        $this->assertEquals('month', $stripe->normalize_interval('Monthly'));
        $this->assertEquals('year', $stripe->normalize_interval('annual'));
        $this->assertEquals('week', $stripe->normalize_interval('weekly'));
        $this->assertEquals('day', $stripe->normalize_interval('daily'));
        $this->assertEquals('month', $stripe->normalize_interval('unknown'));
    }

    public function test_charge_amount_converts_usd_to_cents()
    {
        $stripe = $this->gateway();
        $this->assertEquals(1000, $stripe->charge_amount(10));
    }

    public function test_charge_amount_skips_conversion_for_zero_decimal_currency()
    {
        $stripe = $this->gateway();
        $stripe->setCurrency('JPY');
        $this->assertEquals(10, $stripe->charge_amount(10));
    }

    public function test_setters_are_fluent()
    {
        $stripe = new StripePay();
        $this->assertSame($stripe, $stripe->setWebhookSecret('whsec_test'));
        $this->assertSame($stripe, $stripe->setRecurringInterval('yearly', 2));
        $this->assertSame($stripe, $stripe->setDestinationAccountId('acct_123'));
        $this->assertSame($stripe, $stripe->setApplicationFeeAmount(250));
    }

    public function test_gateway_name_returns_stripe()
    {
        $stripe = $this->gateway();
        $this->assertEquals('stripe', $stripe->gateway_name());
    }

    public function test_supported_currency_list_contains_common_currencies()
    {
        $stripe = $this->gateway();
        $currencies = $stripe->supported_currency_list();
        $this->assertIsArray($currencies);
        $this->assertContains('USD', $currencies);
        $this->assertContains('EUR', $currencies);
        $this->assertContains('INR', $currencies);
    }
}
