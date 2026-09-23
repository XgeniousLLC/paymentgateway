<?php

namespace Xgenious\Paymentgateway\Tests\Gateways;

use Xgenious\Paymentgateway\Base\RecurringSupport;
use Xgenious\Paymentgateway\Base\SubscriptionLifecycle;
use Xgenious\Paymentgateway\Tests\TestCase;
use Xgenious\Paymentgateway\Base\Gateways\PaystackPay;
use Xgenious\Paymentgateway\Base\Gateways\FlutterwavePay;
use Xgenious\Paymentgateway\Base\Gateways\MolliePay;
use Xgenious\Paymentgateway\Base\Gateways\PaypalPay;
use Xgenious\Paymentgateway\Base\Gateways\CashFreePay;
use Xgenious\Paymentgateway\Base\Gateways\MidtransPay;
use Xgenious\Paymentgateway\Base\Gateways\XenditPay;
use Xgenious\Paymentgateway\Base\Gateways\MercadoPagoPay;
use Xgenious\Paymentgateway\Base\Gateways\PayFastPay;
use Xgenious\Paymentgateway\Base\Gateways\SquarePay;
use Xgenious\Paymentgateway\Base\Gateways\AuthorizeDotNetPay;
use Xgenious\Paymentgateway\Base\Gateways\PayTabsPay;
use Xgenious\Paymentgateway\Base\Gateways\PaytmPay;

class RecurringContractsTest extends TestCase
{
    public static function recurringGateways(): array
    {
        return [
            [new PaystackPay()],
            [new FlutterwavePay()],
            [new MolliePay()],
            [new PaypalPay()],
            [new CashFreePay()],
            [new MidtransPay()],
            [new XenditPay()],
            [new MercadoPagoPay()],
            [new PayFastPay()],
            [new SquarePay()],
            [new AuthorizeDotNetPay()],
            [new PayTabsPay()],
            [new PaytmPay()],
        ];
    }

    /**
     * @dataProvider recurringGateways
     */
    public function test_gateway_implements_recurring_contracts($gateway)
    {
        $this->assertInstanceOf(RecurringSupport::class, $gateway);
        $this->assertInstanceOf(SubscriptionLifecycle::class, $gateway);
        $this->assertTrue(method_exists($gateway, 'charge_customer_recurring'));
        $this->assertTrue(method_exists($gateway, 'ipn_response_recurring'));
        $this->assertTrue(method_exists($gateway, 'cancel_subscription'));
        $this->assertTrue(method_exists($gateway, 'pause_subscription'));
        $this->assertTrue(method_exists($gateway, 'resume_subscription'));
        $this->assertTrue(method_exists($gateway, 'fetch_subscription'));
    }

    public function test_paystack_interval_labels()
    {
        $paystack = new PaystackPay();
        $this->assertEquals('monthly', $paystack->recurring_interval_label('monthly'));
        $this->assertEquals('annually', $paystack->recurring_interval_label('yearly'));
        $this->assertEquals('weekly', $paystack->recurring_interval_label('weekly'));
    }

    public function test_flutterwave_duration_labels()
    {
        $flutterwave = new FlutterwavePay();
        $this->assertEquals('monthly', $flutterwave->recurring_duration_label('monthly'));
        $this->assertEquals('yearly', $flutterwave->recurring_duration_label('annual'));
        $this->assertEquals('weekly', $flutterwave->recurring_duration_label('weekly'));
    }

    public function test_mollie_interval_labels()
    {
        $mollie = new MolliePay();
        $this->assertEquals('1 month', $mollie->recurring_interval_label('monthly'));
        $this->assertEquals('12 months', $mollie->recurring_interval_label('yearly'));
        $this->assertEquals('3 months', $mollie->recurring_interval_label('quarterly'));
    }

    public function test_paypal_billing_cycle()
    {
        $paypal = new PaypalPay();
        $cycle = $paypal->recurring_cycle('monthly');
        $this->assertEquals('MONTH', $cycle['interval_unit']);
        $this->assertEquals(1, $cycle['interval_count']);
        $quarterly = $paypal->recurring_cycle('quarterly');
        $this->assertEquals('MONTH', $quarterly['interval_unit']);
        $this->assertEquals(3, $quarterly['interval_count']);
    }

    public function test_cashfree_frequency()
    {
        $cashfree = new CashFreePay();
        $freq = $cashfree->recurring_frequency('monthly');
        $this->assertEquals('monthly', $freq['unit']);
        $yearly = $cashfree->recurring_frequency('yearly');
        $this->assertEquals('yearly', $yearly['unit']);
    }

    public function test_midtrans_schedule()
    {
        $midtrans = new MidtransPay();
        $schedule = $midtrans->recurring_schedule('monthly');
        $this->assertEquals('month', $schedule['interval_unit']);
        $this->assertEquals(1, $schedule['interval']);
        $yearly = $midtrans->recurring_schedule('yearly');
        $this->assertEquals(12, $yearly['interval']);
    }

    public function test_payfast_recurring_fields()
    {
        $payfast = new PayFastPay();
        $fields = $payfast->recurring_fields('monthly');
        $this->assertEquals(1, $fields['subscription_type']);
        $this->assertEquals(0, $fields['cycles']);
        $yearly = $payfast->recurring_fields('yearly');
        $this->assertEquals(4, $yearly['subscription_type']);
    }

    public function test_square_cadence()
    {
        $square = new SquarePay();
        $cadence = $square->recurring_cadence('monthly');
        $this->assertEquals('EVERY_MONTH', $cadence['name']);
        $annual = $square->recurring_cadence('yearly');
        $this->assertEquals('ANNUAL', $annual['name']);
    }

    public function test_authorizenet_schedule()
    {
        $anet = new AuthorizeDotNetPay();
        $schedule = $anet->recurring_schedule('monthly');
        $this->assertEquals('months', $schedule['unit']);
        $this->assertEquals(1, $schedule['length']);
        $weekly = $anet->recurring_schedule('weekly');
        $this->assertEquals('days', $weekly['unit']);
        $this->assertEquals(7, $weekly['length']);
    }

    public function test_paytm_frequency()
    {
        $paytm = new PaytmPay();
        $freq = $paytm->recurring_frequency('monthly');
        $this->assertEquals(3, $freq['unit']);
        $this->assertEquals(1, $freq['count']);
        $yearly = $paytm->recurring_frequency('yearly');
        $this->assertEquals(4, $yearly['unit']);
    }

    public function test_mercadopago_frequency()
    {
        $mp = new MercadoPagoPay();
        $freq = $mp->recurring_frequency('monthly');
        $this->assertEquals('months', $freq['frequency_type']);
        $this->assertEquals(1, $freq['frequency']);
        $quarterly = $mp->recurring_frequency('quarterly');
        $this->assertEquals(3, $quarterly['frequency']);
    }

    public function test_xendit_interval_labels()
    {
        $xendit = new XenditPay();
        $label = $xendit->recurring_interval_label('monthly');
        $this->assertEquals('MONTH', $label['unit']);
        $this->assertEquals(1, $label['count']);
        $yearly = $xendit->recurring_interval_label('yearly');
        $this->assertEquals('YEAR', $yearly['unit']);
    }
}
