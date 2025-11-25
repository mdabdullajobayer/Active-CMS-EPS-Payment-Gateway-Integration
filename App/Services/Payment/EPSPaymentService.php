<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EPSPaymentService
{
    // Generate x-hash according to EPS API
    private static function generateHash($data)
    {
        $hashKey = env('EPS_HASH_KEY'); // Base64 or plain key
        $encoded = utf8_encode($hashKey);  

        return base64_encode(
            hash_hmac('sha512', $data, $encoded, true)
        );
    }

    /**
     * 1️⃣ Get Token API
     */
    public static function getToken()
    {
        $body = [
            'userName' => env('EPS_USER_NAME'),
            'password' => env('EPS_PASSWORD'),
        ];

        // Hash required only with userName
        $xHash = self::generateHash((string) $body['userName']);

        $base = rtrim(env('EPS_API_BASE_URL', 'https://sandboxpgapi.eps.com.bd/v1'), '/');
        $url = $base . '/Auth/GetToken';

        try {
            $response = Http::withHeaders([
                'x-hash' => $xHash
            ])->post($url, $body);
            return $response->json();
        } catch (\Throwable $e) {
            Log::error('EPS GetToken failed: '.$e->getMessage(), ['exception' => $e]);
            return ['error' => 'token_request_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * 2️⃣ Initialize Payment API (ONLY REQUIRED FIELDS)
     */
    public static function initializePayment(array $data)
    {
        // Token
        $tokenResponse = self::getToken();
        $token = $tokenResponse['token'] ?? null;
        
        if (!$token) {
            // bubble up token error structure
            return ['error' => 'Token generation failed', 'details' => $tokenResponse];
        }

        // Hash param = merchantTransactionId
        $xHash = self::generateHash((string) ($data['merchantTransactionId'] ?? ''));

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'x-hash'        => $xHash
        ];

        $base = rtrim(env('EPS_API_BASE_URL', 'https://sandboxpgapi.eps.com.bd/v1'), '/');
        $url = $base . '/EPSEngine/InitializeEPS';

        // Ensure EPS expects string values for these fields (avoid JSON number -> server-side string mismatch)
        $body = [
            'merchantId'             => (string) env('EPS_MERCHANT_ID'),
            'storeId'                => (string) env('EPS_STORE_ID'),
            'CustomerOrderId'        => (string) ($data['CustomerOrderId'] ?? ''),
            'merchantTransactionId'  => (string) ($data['merchantTransactionId'] ?? ''),
            'transactionTypeId'      => (string) (isset($data['transactionTypeId']) ? $data['transactionTypeId'] : '1'),
            'totalAmount'            => (string) ($data['totalAmount'] ?? ''),

            'successUrl'             => (string) ($data['successUrl'] ?? ''),
            'failUrl'                => (string) ($data['failUrl'] ?? ''),
            'cancelUrl'              => (string) ($data['cancelUrl'] ?? ''),

            'customerName'           => (string) ($data['customerName'] ?? ''),
            'customerEmail'          => (string) ($data['customerEmail'] ?? ''),
            'customerAddress'        => (string) ($data['customerAddress'] ?? ''),
            'customerCity'           => (string) ($data['customerCity'] ?? ''),
            'customerState'          => (string) ($data['customerState'] ?? ''),
            'customerPostcode'       => (string) ($data['customerPostcode'] ?? ''),
            'customerCountry'        => (string) ($data['customerCountry'] ?? ''),
            'customerPhone'          => (string) ($data['customerPhone'] ?? ''),

            'productName'            => (string) ($data['productName'] ?? ''),
        ];

        try {
            $response = Http::withHeaders($headers)
                ->post($url, $body);
            return $response->json();
        } catch (\Throwable $e) {
            Log::error('EPS InitializeEPS failed: '.$e->getMessage(), ['exception' => $e]);
            return ['error' => 'initialize_request_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * 3️⃣ Verify Transaction
     */
    public static function verifyTransaction($merchantTransactionId)
    {
        $tokenResponse = self::getToken();
        $token = $tokenResponse['token'] ?? null;
        if (!$token) {
            return ['error' => 'Token generation failed', 'details' => $tokenResponse];
        }
        
        $xHash = self::generateHash((string) $merchantTransactionId);

        $base = rtrim(env('EPS_API_BASE_URL', 'https://sandboxpgapi.eps.com.bd/v1'), '/');
        $url = $base . '/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . urlencode((string) $merchantTransactionId);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'x-hash'        => $xHash,
            ])->get($url, [
                'merchantTransactionId' => (string) $merchantTransactionId
            ]);

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('EPS verifyTransaction failed: '.$e->getMessage(), ['exception' => $e]);
            return ['error' => 'verify_request_failed', 'message' => $e->getMessage()];
        }
    }
}
