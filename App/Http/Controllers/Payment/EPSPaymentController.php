<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\EPSPaymentService;
use Illuminate\Http\Request;
use App\Models\CombinedOrder;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Utility\EmailUtility;
use App\Utility\NotificationUtility;
use Session;

class EPSPaymentController extends Controller
{
    public function pay(Request $request)
    {
        $combined_order_id = $request->session()->get('combined_order_id');
        $combined_order = CombinedOrder::find($combined_order_id);

        if (!$combined_order) {
            return redirect()->route('checkout')->withErrors('Order not found for payment.');
        }

        // Try to decode shipping_address from combined_order (stored JSON)
        $shipping = [];
        if (!empty($combined_order->shipping_address)) {
            $decoded = json_decode($combined_order->shipping_address, true);
            if (is_array($decoded)) {
                $shipping = $decoded;
            }
        }

        // Prefer request inputs; fallback to combined_order shipping_address fields
        $customerName    = $request->input('name')         ?: ($shipping['name'] ?? null);
        $customerEmail   = $request->input('email')        ?: ($shipping['email'] ?? null);
        $customerAddress = $request->input('address')      ?: ($shipping['address'] ?? null);
        $customerCity    = $request->input('city_id')     ?: ($shipping['city'] ?? ($shipping['city_id'] ?? null));
        $customerState   = $request->input('state_id')    ?: ($shipping['state'] ?? ($shipping['state_id'] ?? null));
        $customerPostal  = $request->input('postal_code') ?: ($shipping['postal_code'] ?? null);
        $customerCountry = $request->input('country_id')  ?: ($shipping['country'] ?? ($shipping['country_id'] ?? null));
        $customerPhone   = $request->input('phone')       ?: ($shipping['phone'] ?? null);

        // Log when fallback is used to help debugging
        if ($request->missing('name') || $request->missing('email') || $request->missing('address')) {
            Log::info('EPS payment falling back to combined_order shipping_address', [
                'combined_order_id' => $combined_order->id,
                'shipping_available' => !empty($shipping),
            ]);
        }

        $paymentData = [
            'CustomerOrderId'       => $combined_order->id,
            'merchantTransactionId' => time() . rand(1000, 9999),
            'totalAmount'           => $combined_order->grand_total,

            // callback URLs
            'successUrl'            => route('eps.success'),
            'failUrl'               => route('eps.fail'),
            'cancelUrl'             => route('eps.cancel'),

            // customer info (request first, fallback to combined_order shipping_address)
            'customerName'          => $customerName,
            'customerEmail'         => $customerEmail,
            'customerAddress'       => $customerAddress,
            'customerCity'          => $customerCity,
            'customerState'         => $customerState,
            'customerPostcode'      => $customerPostal,
            'customerCountry'       => $customerCountry,
            'customerPhone'         => $customerPhone,

            // product
            'productName'           => "Order #" . $combined_order->id,
        ];

        Log::info('EPS Payment Data prepared', ['paymentData' => $paymentData]);

        try {
            $response = EPSPaymentService::initializePayment($paymentData);
        } catch (\Exception $e) {
            Log::error('EPS initializePayment failed: '.$e->getMessage(), ['exception' => $e]);
            return redirect()->route('checkout')->withErrors('EPS Payment initialization failed. Please contact support.');
        }

        if (isset($response['RedirectURL'])) {
            return redirect($response['RedirectURL']);
        }

        return redirect()->route('checkout')->withErrors('EPS Payment initialization failed!');
    }

    public function success(Request $request)
    {
        // return $request->all();
        $merchantTransactionId = $request->input('MerchantTransactionId') 
            ?? $request->input('MerchantTransactionId') 
            ?? $request->input('EPSTransactionId_') 
            ?? null;

            
        $combined_order_id = $request->session()->get('combined_order_id');

        $verification = null;
        if ($merchantTransactionId) {
            try {
                $verification = EPSPaymentService::verifyTransaction($merchantTransactionId);
            } catch (\Throwable $e) {
                Log::error('EPS verifyTransaction exception: '.$e->getMessage(), ['exception' => $e]);
            }
        }

        // Try to determine combined order id from verification if session missing
        if (!$combined_order_id) {
            $combined_order_id = $verification['CustomerOrderId'] 
                ?? ($verification['response']['CustomerOrderId'] ?? null);
        }

        $combined_order = $combined_order_id ? CombinedOrder::find($combined_order_id) : null;

        // Determine success heuristically from verification response
        $isPaid = false;
        if (is_array($verification) && !isset($verification['error'])) {
            if (isset($verification['Status']) && $verification['Status'] === "Success") {
                $isPaid = true;
            } 
        } else {
            // If no verification available but we have a combined order and request suggests success, treat as success fallback
            if ($combined_order && ($request->input('status') === 'success' || $request->input('payment_status') === 'success')) {
                $isPaid = true;
            }
        }

        if ($isPaid && $combined_order) {
            foreach ($combined_order->orders as $orderRow) {
                $order = Order::find($orderRow->id);
                if (!$order) continue;
                $order->payment_status = 'paid';
                $order->payment_details = json_encode($verification ?: $request->all());
                $order->payment_type = 'eps';
                $order->save();

                // email & commission & notifications
                try {
                    // EmailUtility::order_email($order, 'paid');
                } catch (\Throwable $e) {
                    Log::error('EmailUtility order_email failed: '.$e->getMessage());
                }

                try {
                    calculateCommissionAffilationClubPoint($order);
                } catch (\Throwable $e) {
                    Log::error('Commission calculation failed: '.$e->getMessage());
                }

                if ($order->notified == 0) {
                    try {
                        // NotificationUtility::sendOrderPlacedNotification($order);
                        $order->notified = 1;
                        $order->save();
                    } catch (\Throwable $e) {
                        Log::error('NotificationUtility failed: '.$e->getMessage());
                    }
                }
            }

            Session::put('combined_order_id', $combined_order->id);
            flash(translate('Payment completed successfully'))->success();
            return redirect()->route('order_confirmed');
        }

        // fallback: mark as failed if we could load the combined order
        if ($combined_order) {
            foreach ($combined_order->orders as $orderRow) {
                $order = Order::find($orderRow->id);
                if (!$order) continue;
                $order->payment_status = 'failed';
                $order->payment_details = json_encode($verification ?: $request->all());
                $order->save();
            }
        }
        return redirect()->route('checkout')->withErrors('EPS Payment could not be verified as successful. If money was deducted, contact support.');
    }

    // Called on explicit failure callback from EPS
    public function fail(Request $request)
    {
        $combined_order_id = $request->session()->get('combined_order_id') 
            ?? $request->input('CustomerOrderId') 
            ?? $request->input('customerOrderId');

        if ($combined_order_id) {
            $combined_order = CombinedOrder::find($combined_order_id);
            if ($combined_order) {
                foreach ($combined_order->orders as $orderRow) {
                    $order = Order::find($orderRow->id);
                    if (!$order) continue;
                    $order->payment_status = 'failed';
                    $order->payment_details = json_encode($request->all());
                    $order->save();
                }
            }
        }

        $request->session()->forget('order_id');
        $request->session()->forget('payment_data');
        flash(translate('Payment Failed'))->warning();
        return redirect()->route('home');
    }

    // Called when the buyer cancels the payment at EPS side
    public function cancel(Request $request)
    {
        $combined_order_id = $request->session()->get('combined_order_id') 
            ?? $request->input('CustomerOrderId') 
            ?? $request->input('customerOrderId');

        if ($combined_order_id) {
            $combined_order = CombinedOrder::find($combined_order_id);
            if ($combined_order) {
                foreach ($combined_order->orders as $orderRow) {
                    $order = Order::find($orderRow->id);
                    if (!$order) continue;
                    $order->payment_status = 'cancelled';
                    $order->payment_details = json_encode($request->all());
                    $order->save();
                }
            }
        }

        $request->session()->forget('order_id');
        $request->session()->forget('payment_data');
        flash(translate('Payment cancelled'))->warning();
        return redirect()->route('home');
    }
}
