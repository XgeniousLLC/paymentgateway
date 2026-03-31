<?php

namespace Xgenious\Paymentgateway\Base\Gateways;
use Xgenious\Paymentgateway\Base\GlobalCurrency;
use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\IndianCurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Illuminate\Support\Facades\Http;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Models\PaymentMeta;
use Illuminate\Support\Str;
use Xgenious\Paymentgateway\Traits\RussianCurrencySupport;

class YooMoneyPay extends PaymentGatewayBase
{
    use RussianCurrencySupport, CurrencySupport, PaymentEnvironment;

    protected $shop_id;
    protected $secret_key;
    protected $lang ='ru';
    /**
     * @inheritDoc
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return  $amount;
        }
        return  $this->get_amount_in_rub($amount);
    }

    /**
     * @inheritDoc
     */
    public function ipn_response(array $args = [])
    {
        $request = request();
        $event_type = $request->event;
        $object = $request->object;
        $payment_id = $object['id'] ?? "";
        $meta_data = $object['metadata'] ?? "";
        $status = $object['status'] ?? "";
        $isPaid = $object['paid'] ?? false;

        if($event_type === 'payment.succeeded' && $status === 'succeeded' && $isPaid){

            $payment_meta_info =  PaymentMeta::where(["gateway" => "yoomoney","order_id" => $meta_data['order_id'] ?? ''])->first();

            //code for revalidate the payment
            $client = new \YooKassa\Client();
            $client->setAuth($this->getShopId(), $this->getSecretKey());
            $payment = $client->getPaymentInfo($payment_id);
            if ($payment->getStatus() === 'succeeded'){
                return $this->verified_data([
                    "status" => "complete",
                    "transaction_id" => $payment_id,
                    "order_id" => PaymentGatewayHelpers::unwrapped_id($meta_data['order_id']),
                    'payment_type' => $payment_meta_info?->type
                ]);
            }
            return ["status" => "failed"];
        }

        return ["status" => "failed"];
    }

    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {
        $client = new \YooKassa\Client();
        try {
            $client->setAuth($this->getShopId(), $this->getSecretKey());
        }catch (\Exception $exception){
            abort(500,'Wrong api credentials');
        }
        $order_id = PaymentGatewayHelpers::wrapped_id($args['order_id']);
        $amount = $this->charge_amount($args['amount']);

        try {
            $builder = \YooKassa\Request\Payments\CreatePaymentRequest::builder();
            $builder->setAmount($amount)
                ->setCurrency(\YooKassa\Model\CurrencyCode::RUB)
                ->setCapture(true)
                ->setDescription($args['description'])
                ->setMetadata([
                    'cms_name'       => $args['title'],
                    'order_id'       => $order_id,
                    'language'       => $this->getLang(),
                    'transaction_id' => Str::uuid()->toString(),
                ]);

            // We set up a page for redirection after payment
            $builder->setConfirmation([
                'type'      => \YooKassa\Model\Payment\ConfirmationType::REDIRECT,
                'returnUrl' =>  $args['success_url']
            ]);


            // Create a request object
            $request = $builder->build();

            // You can change the data if necessary.
            $request->setDescription($request->getDescription() . ' - merchant comment');

            $idempotenceKey = uniqid('', true);
            $response = $client->createPayment($request, $idempotenceKey);

            // store meta data about the payment
            PaymentMeta::create([
                "gateway" => "yoomoney",
                "amount" => $amount,
                "order_id" => $order_id,
                "meta_data" => json_encode([
                    "idempotenceKey" => $idempotenceKey
                ]),
                "session_id" => $idempotenceKey,
                "type" => $args["payment_type"],
                "track" => Str::random(60),
            ]);

            //we get confirmationUrl for further redirection
            $confirmationUrl = $response->getConfirmation()->getConfirmationUrl();
            return redirect()->away($confirmationUrl);
        } catch (\Exception $e) {
            abort(501, $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ["RUB"];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "RUB";
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return "yoomoney";
    }

    /* set app id */
    public function setLang($lang)
    {
        $this->lang = $lang;
        return $this;
    }

    /* set app id */
    public function setShopId($shop_id)
    {
        $this->shop_id = $shop_id;
        return $this;
    }
    /* set app secret */
    public function setSecretKey($secret_key)
    {
        $this->secret_key = $secret_key;
        return $this;
    }
    /* get app id */
    private function getShopId()
    {
        return $this->shop_id;
    }
    /* get secret key */
    private function getSecretKey()
    {
        return $this->secret_key;
    }

    /* get secret key */
    private function getLang()
    {
        return $this->lang;
    }

}
