<?php

namespace Xgenious\Paymentgateway\Http\Controllers;


use Illuminate\Http\Request;
use Xgenious\Paymentgateway\Facades\XgPaymentGateway;

class StripePaymentController extends Controller
{
    public function charge_customer(Request $request){
        try{
            $gateway = XgPaymentGateway::stripe();
            if ($request->filled('recurring_interval')) {
                $gateway->setRecurringInterval($request->recurring_interval, $request->input('recurring_interval_count', 1));
            }
            if ($request->filled('destination_account_id')) {
                $gateway->setDestinationAccountId($request->destination_account_id);
            }
            if ($request->filled('application_fee_amount')) {
                $gateway->setApplicationFeeAmount($request->application_fee_amount);
            }
            $method = $request->boolean('is_subscription') || $request->payment_type === 'monthly'
                ? 'charge_customer_recurring_from_controller'
                : 'charge_customer_from_controller';
            $stripe_session = $gateway->$method([
                'amount' => $request->amount,
                'charge_amount' => $request->charge_amount,
                'title' => $request->title,
                'description' => $request->description,
                'ipn_url' => $request->ipn_url,
                'order_id' => $request->order_id,
                'track' => $request->track,
                'cancel_url' => $request->cancel_url,
                'success_url' => $request->success_url,
                'email' => $request->email,
                'name' => $request->name,
                'payment_type' => $request->payment_type,
                'secret_key' => $request->secret_key,
                'currency' => $request->currency,
                'is_subscription' => $request->boolean('is_subscription'),
                'recurring_interval' => $request->input('recurring_interval'),
                'recurring_interval_count' => $request->input('recurring_interval_count', 1),
                'destination_account_id' => $request->input('destination_account_id'),
                'application_fee_amount' => $request->input('application_fee_amount'),
            ]);
            return response()->json(['id' => $stripe_session['id']]);
        }catch(\Exception $e){
            return response()->json(['msg' => $e->getMessage(),'type' => 'danger']);
        }
    }
}
