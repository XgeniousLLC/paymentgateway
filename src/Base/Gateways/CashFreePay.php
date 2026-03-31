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

class CashFreePay extends PaymentGatewayBase
{
    use IndianCurrencySupport, CurrencySupport, PaymentEnvironment;

    protected $app_id;
    protected $secret_key;
    protected $api_version = "2023-08-01";
    /**
     * @inheritDoc
     */
    public function charge_amount($amount)
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())){
            return $this->is_decimal($amount) ? $amount : number_format((float)$amount,2,'.','');
        }
        return $this->is_decimal( $this->get_amount_in_inr($amount)) ? $this->get_amount_in_inr($amount) :number_format((float) $this->get_amount_in_inr($amount),2,'.','');
    }

    /**
     * @inheritDoc
     */
    public function ipn_response(array $args = [])
    {
        $order_id = request()->get("order_id");

        // If order_id is null/empty, try to extract it from the full query string
        if (empty($order_id)) {
            $query_string = request()->server('QUERY_STRING');
            
            // Use regex to find order_id=value pattern
            if (preg_match('/order_id=([^&]+)/', $query_string, $matches)) {
                $order_id = $matches[1];
            }
        }


        if (request()->type === "PAYMENT_SUCCESS_WEBHOOK") {
            //handle webhook
            $order_id = request()->data?->order?->order_id;
        }

        $payment = PaymentMeta::where("order_id", $order_id)->first();

        $cf_order_id =
            json_decode($payment->meta_data, true)["cf_order_id"] ?? "";

        $req = Http::withHeaders($this->getHeaders())->get(
            $this->get_api_url() . "/pg/orders/" . $order_id
        );
        $result = $req->object();

        if ($req->ok() && $result->order_status === "PAID") {
            return $this->verified_data([
                "status" => "complete",
                "transaction_id" => $result->cf_order_id,
                "order_id" => substr($payment->order_id, 5, -5),
            ]);
        }
        return ["status" => "failed"];
    }

    /**
     * @inheritDoc
     */
    public function charge_customer(array $args)
    {
        $customer_details = $this->getCustomerDetails($args);

        $amount = $this->charge_amount($args["amount"]);
        $order_id = PaymentGatewayHelpers::wrapped_id($args["order_id"]);
        $ipn_url_with_order_id = $args["ipn_url"] . (strpos($args["ipn_url"], '?') !== false ? '&' : '?') . "order_id=" . $order_id;

        $data = [
            "order_id" => $order_id,
            "order_amount" => $amount,
            "order_currency" => "INR",
            "customer_details" => [
                "customer_id" => $customer_details->customer_uid,
                //"customer_uid" => $customer_details->customer_uid,
                "customer_name" => !empty($customer_details->customer_name) ? $customer_details->customer_name : $args['name'],
                "customer_email" => !empty($customer_details->customer_email) ? $customer_details->customer_email : $args['email'],
                "customer_phone" => str_replace(' ','',$customer_details->customer_phone), //+91-985-559-9234
            ],
            "order_meta" => [
                "return_url" => $ipn_url_with_order_id,
            ],
        ];
        $req = Http::withHeaders($this->getHeaders())->post(
            $this->get_api_url() . "/pg/orders",
            $data
        );
      
        if ($req->ok()) {
            $result = $req->object();
            $payment_session_id = $result->payment_session_id;
            $resData = [
                "env" => $this->getEnv() ? "sandbox" : "production", //production
                "payment_session_id" => $result->payment_session_id,
                "success_url" =>
                    $args["success_url"] . "?order_id=" . $order_id,
                "cancel_url" => $args["cancel_url"] . "?order_id=" . $order_id,
            ];
            PaymentMeta::create([
                "gateway" => "cashfree",
                "amount" => $amount,
                "order_id" => $order_id,
                "meta_data" => json_encode([
                    "cf_order_id" => $result->cf_order_id,
                    "payment_session_id" => $result->payment_session_id,
                    "order_status" => $result->order_status,
                ]),
                "session_id" => $result->payment_session_id,
                "type" => $args["payment_type"],
                "track" => Str::random(60),
            ]);

            // redirect with a blade page for initiate payment checkout
            return view("paymentgateway::cashfree", [
                "payment_data" => $resData,
            ]);
        }
        abort(500, "cashfree api error");
    }

    /**
     * @inheritDoc
     */
    public function supported_currency_list()
    {
        return ["INR"];
    }

    /**
     * @inheritDoc
     */
    public function charge_currency()
    {
        if (in_array($this->getCurrency(), $this->supported_currency_list())) {
            return $this->getCurrency();
        }
        return "INR";
    }

    /**
     * @inheritDoc
     */
    public function gateway_name()
    {
        return "cashfree";
    }

    /* set app id */
    public function setAppId($app_id)
    {
        $this->app_id = $app_id;
        return $this;
    }
    /* set app secret */
    public function setSecretKey($secret_key)
    {
        $this->secret_key = $secret_key;
        return $this;
    }
    /* get app id */
    private function getAppId()
    {
        return $this->app_id;
    }
    /* get secret key */
    private function getSecretKey()
    {
        return $this->secret_key;
    }
    private function getHeaders()
    {
        return [
            "X-Client-Secret" => $this->getSecretKey(),
            "X-Client-Id" => $this->getAppId(),
            "x-api-version" => $this->api_version,
            "Content-Type" => "application/json",
            "Accept" => "application/json",
        ];
    }
    private function get_api_url()
    {
        $prefix = $this->getEnv() ? "sandbox" : "api";
        return "https://" . $prefix . ".cashfree.com";
    }

    private function getCustomerDetails($args){
        $req = Http::withHeaders([
            "X-Client-Secret" => $this->getSecretKey(),
            "X-Client-Id" => $this->getAppId(),
            "x-api-version" => $this->api_version,
            "Content-Type" => "application/json",
            "Accept" => "application/json",
        ])->post($this->get_api_url() . "/pg/customers", [
            "customer_phone" => $args["phone"] ?? "9999999999",
            "customer_email" => $args["email"],
            "customer_name" => $args["name"],
        ]);
      
        return $req->ok() ? $req->object() : null;
    }
}