<?php

declare(strict_types=1);

use Paymos\Exception\DuplicateEventException;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use PaymosOpenCart\InMemoryEventStore;

function test_opencart_event_store_blocks_duplicate_after_commit()
{
    $store = new InMemoryEventStore();
    $body = json_encode(opencart_invoice_event('evt_1', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);
    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_sandbox'), $store);

    $verifier->process($signature, $body, 1709000000);
    $store->commit();

    try {
        $verifier->process($signature, $body, 1709000000);
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'duplicate exception expected.');
        return;
    }

    throw new RuntimeException('Committed event id must block duplicate webhook processing.');
}

function test_opencart_event_store_release_allows_retry()
{
    $store = new InMemoryEventStore();

    assertTrueValue($store->remember('evt_retry', 3600), 'first remember must reserve event id.');
    $store->release();
    assertTrueValue($store->remember('evt_retry', 3600), 'released event id must be retriable.');
}
