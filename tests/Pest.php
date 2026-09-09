<?php

use Techork\PaymentService\Tests\TestCase;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

pest()->extend(TestCase::class)->in('Unit');

/**
 * The payer these tests hand to a command, complete, because a {@see Customer} has no partial
 * form — an id, a person and an address or nothing at all.
 *
 * That completeness is the change worth knowing about here. The id, the identity and the address
 * used to be three optional arguments a caller could supply any subset of, which is how a
 * provider-side customer came to be built out of whatever billing address rode along with the
 * payment. A test that wants to say "no payer" passes null, not a fragment.
 */
function paynetSuiteCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?PhoneNumber $phone = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? CustomerId::fromString('01920000-0000-7000-8000-00000000cafe'),
        identity: new CustomerIdentity($firstName, $lastName, $email, $phone),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}
