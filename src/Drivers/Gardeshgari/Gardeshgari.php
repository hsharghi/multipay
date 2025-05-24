<?php

namespace Shetabit\Multipay\Drivers\Gardeshgari;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Gardeshgari extends Driver
{
    /**
     * Nextpay Client.
     */
    protected \GuzzleHttp\Client $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Redirect URI returned from gateway
     *
     * @var string
     */
    protected $redirectUrl;

    /**
     * Gardeshgari constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        try {
            // Prepare request data
            $data = [
                'amount' => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10),
                'invoiceNumber' => $this->getInvoiceNumber(),
                'invoiceDate' => date('Y-m-d'),
                'callback' => $this->getCallbackUrl(),
                'token' => $this->settings->apiToken,
            ];

            // Add optional parameters if provided
            $data['mobile'] = $this->invoice->getDetail('phone')
                ?? $this->invoice->getDetail('cellphone')
                ?? $this->invoice->getDetail('mobile');

            $data['email'] = $this->invoice->getDetail('email');


            // Make the API request
            $response = $this->client->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    'json' => $data,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ]
                ]
            );

            // Process the response
            $result = json_decode($response->getBody()->getContents(), true);

            // Check if request was successful
            if (isset($result['success']) && $result['success'] === true) {
                $this->redirectUrl = $result['data']['url'];
                $this->invoice->transactionId($result['data']['token']);

                return $this->invoice->getTransactionId();
            } else {
                throw new \Exception('Error getting token: ' . ($result['message'] ?? 'Unknown error'));
            }
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $code = (int)$errorBody['code'] ?? 0;

                throw new PurchaseFailedException($errorBody['message'] ?? $e->getMessage(), $code);
            } else {
                throw new \Exception('Connection Error: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            throw new \Exception('Error: ' . $e->getMessage());
        }
    }

    /**
     * Pay the Invoice
     */
    public function pay(): RedirectionForm
    {

        $payUrl = implode('/', [
            $this->redirectUrl,
            $this->invoice->getTransactionId()
        ]);

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException|PreviouslyVerifiedException
     */
    public function verify(): ReceiptInterface
    {
        $trackingNumber = Request::input('trackingNumber');

        $data = [
            'trackingNumber' => $trackingNumber,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiVerificationUrl,
                [
                    "form_params" => $data,
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        $success = (bool)$body['success'] ?? false;

        if (!$success) {
            $code = (int)$body['code'] ?? 0;
            $message = (int)$body['message'] ?? 'خطای نامشخص در تایید پرداخت';
            $errors = $body['errors'] ?? [];
            throw new InvalidPaymentException($message, $code);
        }

        $refNumber = $body['data']['refNumber'] ?? '';
        $verifiedBefore = $body['data']['verifiedBefore'] ?? false;

        if ($verifiedBefore) {
            throw new PreviouslyVerifiedException('این پرداخت قبلا تایید شده است.', 0);
        }

        $receipt = $this->createReceipt($refNumber);
        $receipt->detail([
            'cardNumber' => $body['data']['cardNumber'] ?? '',
            'traceNo' => $body['data']['refNumber'] ?? '',
            'message' => $body['message'] ?? '',
            'invoiceNumber' => $body['data']['invoiceNumber'] ?? '',
            'invoiceDate' => $body['data']['invoiceDate'] ?? '',
            'amount' => $body['data']['amount'] ?? 0,
        ]);


        return $receipt;
    }

    /**
     * Get invoiceNumber from invoice bag or generate a new one
     *
     * Gardeshgari gateway does not support invoiceNumber with more
     * Than 24 characters long. We have to trim it before using.
     *
     * @return string
     */
    protected function getInvoiceNumber(): string
    {
        if ($invoiceNumber = $this->invoice->getDetail('invoiceNumber')) {
            return substr($invoiceNumber, 0, 24);
        }
        $uuid = $this->invoice->getUuid();
        return substr(str_replace('-', '', $uuid), 0, 24);
    }

    /**
     * Get callbackUrl from invoice bag or settings
     *
     * @return string
     */
    protected function getCallbackUrl(): string
    {
        if ($callbackUrl = $this->invoice->getDetail('callbackUrl')) {
            return $callbackUrl;
        }
        return $this->settings->callbackUrl;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     * @return Receipt
     */
    protected function createReceipt($referenceId): \Shetabit\Multipay\Receipt
    {
        return new Receipt('gardeshgari', $referenceId);
    }
}
