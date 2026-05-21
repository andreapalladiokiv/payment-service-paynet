<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet\Webhook;

use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier as SignatureVerifierContract;

/**
 * Paynet webhook signature.
 *
 *   md5( EventDate + Eventid + EventType + Payment.Amount + Payment.Customer +
 *        Payment.ExternalID + Payment.ID + Payment.Merchant + Payment.StatusDate +
 *        secret )
 *
 * where `secret` = `merchant_security_code` (falling back to `secret_key`).
 * The expected hex digest is delivered in the `Signature` header.
 */
final readonly class SignatureVerifier implements SignatureVerifierContract
{
    private const string SIGNATURE_HEADER = 'Signature';

    public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool
    {
        $provided = $request->getHeaderLine(self::SIGNATURE_HEADER);
        if ($provided === '') {
            return false;
        }

        $credentials = $gateway->getCredentials();
        $secret = $credentials['merchant_security_code'] ?? $credentials['secret_key'] ?? '';
        if ($secret === '') {
            return false;
        }

        $payload = json_decode($request->getBody()->getContents(), true);
        if (! is_array($payload)) {
            return false;
        }

        $fields = [
            (string) ($payload['EventDate'] ?? ''),
            (string) ($payload['Eventid'] ?? ''),
            (string) ($payload['EventType'] ?? ''),
            (string) ($payload['Payment']['Amount'] ?? ''),
            (string) ($payload['Payment']['Customer'] ?? ''),
            (string) ($payload['Payment']['ExternalID'] ?? ''),
            (string) ($payload['Payment']['ID'] ?? ''),
            (string) ($payload['Payment']['Merchant'] ?? ''),
            (string) ($payload['Payment']['StatusDate'] ?? ''),
            $secret,
        ];

        $expected = md5(implode('', $fields));

        return hash_equals($expected, strtolower($provided));
    }
}
