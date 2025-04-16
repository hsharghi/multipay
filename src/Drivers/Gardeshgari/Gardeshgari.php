<?php

namespace Shetabit\Multipay\Drivers\Gardeshgari;

use GuzzleHttp\Client;
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
        $data = [
            'token' => $this->settings->apiToken,
            'invoiceNumber' => $this->invoice->getUuid(),
            'invoiceDate' => date('Y-m-d'),
            'amount' => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
            'callback_uri' => $this->settings->callbackUrl,
        ];

        $data['mobile'] = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');

        $data['email'] = $this->invoice->getDetail('email');


        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "form_params" => $data,
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);
        $success = (bool)$body['success'] ?? false;

        if (!$success) {
            $code = (int)$body['code'] ?? 0;
            $message = (int)$body['message'] ?? 'خطای نامشخص در دریافت توکن';
            $errors = $body['errors'] ?? [];
            throw new PurchaseFailedException($message, $code);
        }

        $url = $body['data']['url'];
        $token = $body['data']['token'];
        $message = $body['message'] ?? '';
        $redirectUrl = "{$url}/{$token}";
        $this->invoice->transactionId($redirectUrl);

        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->invoice->getTransactionId();

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
