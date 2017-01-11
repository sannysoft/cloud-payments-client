<?php

namespace CloudPayments;

use Monolog\Logger;

class Manager
{
    /**
     * @var string
     */
    protected $_url = 'https://api.cloudpayments.ru';

    /**
     * @var string
     */
    protected $_locale = 'en-US';

    /**
     * @var string
     */
    protected $_publicKey;

    /**
     * @var string
     */
    protected $_privateKey;

    /**
     * @param string $publicKey
     * @param string $privateKey
     */
    public function __construct($publicKey, $privateKey)
    {
        $this->_publicKey = $publicKey;
        $this->_privateKey = $privateKey;
        $this->_logger = new Logger('cloudpayments');
    }

    /**
     * You can push handler to monolog logger to store logs
     *
     * @return Logger
     */
    public function getLogger()
    {
        return $this->_logger;
    }

    /**
     * @param string $endpoint
     * @param array $params
     * @return array
     */
    protected function sendRequest($endpoint, array $params = [])
    {
        //Set locale name
        $params['CultureName'] = $this->_locale;

        //Log request
        $this->_logger->debug("Request $endpoint with params: " . http_build_query($params));

        //Make request
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->_url . $endpoint);
        curl_setopt($curl, CURLOPT_USERPWD, sprintf('%s:%s', $this->_publicKey, $this->_privateKey));
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        $result = curl_exec($curl);

        //Log response
        if (curl_errno($curl))
            $this->_logger->error('Request error: ' . curl_error($curl)); else
            $this->_logger->debug("Response $endpoint: $result");

        curl_close($curl);

        return (array)json_decode($result, true);
    }

    /**
     * @return string
     */
    public function getLocale()
    {
        return $this->_locale;
    }

    /**
     * @param string $locale
     */
    public function setLocale($locale)
    {
        $this->_locale = $locale;
    }

    /**
     * @throws Exception\RequestException
     */
    public function test()
    {
        $response = $this->sendRequest('/test');
        if (!$response['Success']) {
            throw new Exception\RequestException($response);
        }
    }

    /**
     * @param float $amount
     * @param string $currency
     * @param string $ipAddress
     * @param string $cardHolderName
     * @param string $cryptogram
     * @param array $params
     * @param bool $requireConfirmation true: 2 step - authorization+charge, false: 1 step
     * @return Model\Required3DS|Model\Transaction
     * @throws Exception\PaymentException
     * @throws Exception\RequestException
     */
    public function chargeCard($amount, $currency, $ipAddress, $cardHolderName, $cryptogram, $params = [], $requireConfirmation = false)
    {
        $endpoint = $requireConfirmation ? '/payments/cards/auth' : '/payments/cards/charge';
        $defaultParams = [
            'Amount' => $amount,
            'Currency' => $currency,
            'IpAddress' => $ipAddress,
            'Name' => $cardHolderName,
            'CardCryptogramPacket' => $cryptogram
        ];

        $response = $this->sendRequest($endpoint, array_merge($defaultParams, $params));

        if ($response['Success']) {
            return Model\Transaction::fromArray($response['Model']);
        }

        if ($response['Message']) {
            throw new Exception\RequestException($response);
        }

        if (isset($response['Model']['ReasonCode']) && $response['Model']['ReasonCode'] !== 0) {
            throw new Exception\PaymentException($response);
        }

        return Model\Required3DS::fromArray($response['Model']);
    }

    /**
     * Charge or authorize payment
     *
     * @param float $amount
     * @param string $currency
     * @param int $accountId
     * @param string $token
     * @param array $params
     * @param bool $requireConfirmation
     * @return Model\Required3DS|Model\Transaction
     * @throws Exception\PaymentException
     * @throws Exception\RequestException
     */
    public function chargeToken($amount, $currency, $accountId, $token, $params = [], $requireConfirmation = false)
    {
        $endpoint = $requireConfirmation ? '/payments/tokens/auth' : '/payments/tokens/charge';
        $defaultParams = [
            'Amount' => $amount,
            'Currency' => $currency,
            'AccountId' => $accountId,
            'Token' => $token,
        ];

        $response = $this->sendRequest($endpoint, array_merge($defaultParams, $params));

        if ($response['Success']) {
            return Model\Transaction::fromArray($response['Model']);
        }

        if ($response['Message']) {
            throw new Exception\RequestException($response);
        }

        if (isset($response['Model']['ReasonCode']) && $response['Model']['ReasonCode'] !== 0) {
            throw new Exception\PaymentException($response);
        }

        return Model\Required3DS::fromArray($response['Model']);
    }

    /**
     * Confirm 3ds transaction after customer entered SMS-code
     *
     * @param string $transactionId
     * @param string $token
     * @return Model\Transaction
     * @throws Exception\PaymentException
     * @throws Exception\RequestException
     */
    public function confirm3DS($transactionId, $token)
    {
        $response = $this->sendRequest('/payments/cards/post3ds', [
            'TransactionId' => $transactionId,
            'PaRes' => $token
        ]);

        if (isset($response['Message']) && $response['Message']) {
            throw new Exception\RequestException($response);
        }

        if (isset($response['Model']['ReasonCode']) && $response['Model']['ReasonCode'] !== 0) {
            throw new Exception\PaymentException($response);
        }

        return Model\Transaction::fromArray($response['Model']);
    }

    /**
     * @param string $transactionId
     * @param float $amount
     * @throws Exception\RequestException
     */
    public function confirmPayment($transactionId, $amount)
    {
        $response = $this->sendRequest('/payments/confirm', [
            'TransactionId' => $transactionId,
            'Amount' => $amount
        ]);

        if (!$response['Success']) {
            throw new Exception\RequestException($response);
        }
    }

    /**
     * @param string $transactionId
     * @throws Exception\RequestException
     */
    public function voidPayment($transactionId)
    {
        $response = $this->sendRequest('/payments/void', [
            'TransactionId' => $transactionId
        ]);

        if (!isset($response['Model']) && !$response['Success']) {
            throw new Exception\RequestException($response);
        }
    }

    /**
     * @param string $transactionId
     * @param float $amount
     * @throws Exception\RequestException
     */
    public function refundPayment($transactionId, $amount)
    {
        $response = $this->sendRequest('/payments/refund', [
            'TransactionId' => $transactionId,
            'Amount' => $amount
        ]);

        if (!$response['Success']) {
            throw new Exception\RequestException($response);
        }
    }

    /**
     * @param string $invoiceId
     * @return Model\Transaction
     * @throws Exception\RequestException
     */
    public function findPayment($invoiceId)
    {
        $response = $this->sendRequest('/payments/find', [
            'InvoiceId' => $invoiceId
        ]);

        if (!isset($response['Model']) && !$response['Success']) {
            throw new Exception\RequestException($response);
        }

        return Model\Transaction::fromArray($response['Model']);
    }

    /**
     * Check if signature is valid for incoming requests from CloudPayments
     *
     * @return bool
     */
    public function verifySignatureFromRequest()
    {
        if (!isset($_SERVER['HTTP_CONTENT_HMAC']))
            return false;

        if ($_SERVER['REQUEST_METHOD'] == 'POST')
            $data = file_get_contents('php://input'); else
            $data = $_SERVER['QUERY_STRING'];

        $localSignature = base64_encode(hash_hmac('sha256', $data, $this->_privateKey, true));
        $passedSignature = $_SERVER['HTTP_CONTENT_HMAC'];

        return $passedSignature == $localSignature;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->_url;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setUrl($value)
    {
        $this->_url = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getPublicKey()
    {
        return $this->_publicKey;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setPublicKey($value)
    {
        $this->_publicKey = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getPrivateKey()
    {
        return $this->_privateKey;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setPrivateKey($value)
    {
        $this->_privateKey = $value;

        return $this;
    }
}