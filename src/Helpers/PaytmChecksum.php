<?php

namespace Xgenious\Paymentgateway\Helpers;
use Exception;

class PaytmChecksum
{
    private static $iv = "@@@@&&&&####$$$$";

    public static function encrypt(string $input, string $key): string
    {
        $key = html_entity_decode($key);

        if (!function_exists('openssl_encrypt')) {
            throw new Exception("OpenSSL is required for encryption but is not available.");
        }

        $data = openssl_encrypt($input, "AES-128-CBC", $key, 0, self::$iv);
        if ($data === false) {
            throw new Exception("Encryption failed: " . openssl_error_string());
        }

        return $data;
    }

    public static function decrypt(string $encrypted, string $key): string
    {
        $key = html_entity_decode($key);

        if (!function_exists('openssl_decrypt')) {
            throw new Exception("OpenSSL is required for decryption but is not available.");
        }

        $data = openssl_decrypt($encrypted, "AES-128-CBC", $key, 0, self::$iv);
        if ($data === false) {
            throw new Exception("Decryption failed: " . openssl_error_string());
        }

        return $data;
    }

    public static function generateSignature($params, string $key): string
    {
        if (empty($params)) {
            throw new Exception("Params cannot be empty");
        }
        if (empty($key)) {
            throw new Exception("Key cannot be empty");
        }

        if (!is_array($params) && !is_string($params)) {
            throw new Exception("String or array expected, " . gettype($params) . " given");
        }
        if (is_array($params)) {
            self::validateParams($params);
            $params = self::getStringByParams($params);
        }
        return self::generateSignatureByString($params, $key);
    }

    public static function verifySignature($params, string $key, string $checksum): bool
    {
        if (empty($params)) {
            throw new Exception("Params cannot be empty");
        }
        if (empty($key)) {
            throw new Exception("Key cannot be empty");
        }

        if (!is_array($params) && !is_string($params)) {
            throw new Exception("String or array expected, " . gettype($params) . " given");
        }
        if (is_array($params)) {
            if (isset($params['CHECKSUMHASH'])) {
                unset($params['CHECKSUMHASH']);
            }
            $params = self::getStringByParams($params);
        }
        return self::verifySignatureByString($params, $key, $checksum);
    }

    private static function generateSignatureByString(string $params, string $key): string
    {
        $salt = self::generateRandomString(4);
        return self::calculateChecksum($params, $key, $salt);
    }

    private static function verifySignatureByString(string $params, string $key, string $checksum): bool
    {
        $paytm_hash = self::decrypt($checksum, $key);
        $salt = substr($paytm_hash, -4);
        return $paytm_hash === self::calculateHash($params, $salt);
    }

    private static function generateRandomString(int $length): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    private static function getStringByParams(array $params): string
    {
        ksort($params);
        $params = array_map(function ($value) {
            return ($value !== null && strtolower($value) !== "null") ? $value : "";
        }, $params);
        return implode("|", $params);
    }

    private static function calculateHash(string $params, string $salt): string
    {
        $finalString = $params . "|" . $salt;
        $hash = hash("sha256", $finalString);
        return $hash . $salt;
    }

    private static function calculateChecksum(string $params, string $key, string $salt): string
    {
        $hashString = self::calculateHash($params, $salt);
        return self::encrypt($hashString, $key);
    }

    private static function validateParams(array $params): void
    {
        $requiredKeys = ['requestType', 'mid', 'websiteName', 'orderId', 'callbackUrl', 'txnAmount', 'userInfo'];
        foreach ($requiredKeys as $key) {
            if (!isset($params[$key])) {
                throw new Exception("Missing required parameter: $key");
            }
        }
    }
}
