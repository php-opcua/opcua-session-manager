<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\SessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Subscription via ManagedClient', function () {

    it('creates a subscription with valid subscriptionId', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(500.0);
            expect($sub->subscriptionId)->toBeInt()->toBeGreaterThan(0);
            expect($sub->revisedPublishingInterval)->toBeGreaterThan(0);

            $status = $client->deleteSubscription($sub->subscriptionId);
            expect(StatusCode::isGood($status))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('creates a monitored item on Counter variable', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(500.0);
            $subId = $sub->subscriptionId;

            $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
            $results = $client->createMonitoredItems($subId, [
                ['nodeId' => $counterNodeId, 'clientHandle' => 1],
            ]);

            expect($results)->toHaveCount(1);
            expect(StatusCode::isGood($results[0]->statusCode))->toBeTrue();
            expect($results[0]->monitoredItemId)->toBeInt()->toBeGreaterThan(0);

            // Cleanup
            $client->deleteMonitoredItems($subId, [$results[0]->monitoredItemId]);
            $client->deleteSubscription($subId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('publishes and gets a valid response structure', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(500.0);
            $subId = $sub->subscriptionId;

            $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
            $monResults = $client->createMonitoredItems($subId, [
                ['nodeId' => $counterNodeId, 'clientHandle' => 1],
            ]);
            $monId = $monResults[0]->monitoredItemId;

            $pub = $client->publish();

            // Through the daemon IPC, notifications may not arrive due to
            // synchronous request/response nature. Verify the publish response
            // structure is correct.
            expect($pub->subscriptionId)->toBe($subId);

            // Cleanup
            $client->deleteMonitoredItems($subId, [$monId]);
            $client->deleteSubscription($subId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('deletes a monitored item', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(500.0);
            $subId = $sub->subscriptionId;

            $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
            $monResults = $client->createMonitoredItems($subId, [
                ['nodeId' => $counterNodeId, 'clientHandle' => 1],
            ]);
            $monId = $monResults[0]->monitoredItemId;

            $deleteResults = $client->deleteMonitoredItems($subId, [$monId]);
            expect($deleteResults)->toHaveCount(1);
            expect(StatusCode::isGood($deleteResults[0]))->toBeTrue();

            $client->deleteSubscription($subId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('deletes a subscription', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(500.0);
            $status = $client->deleteSubscription($sub->subscriptionId);
            expect(StatusCode::isGood($status))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('subscribes to multiple dynamic variables at once', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $sub = $client->createSubscription(500.0);
            $subId = $sub->subscriptionId;

            $counterNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Counter']);
            $randomNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'Random']);
            $sineNodeId = TestHelper::browseToNode($client, ['TestServer', 'Dynamic', 'SineWave']);

            $monResults = $client->createMonitoredItems($subId, [
                ['nodeId' => $counterNodeId, 'clientHandle' => 1],
                ['nodeId' => $randomNodeId, 'clientHandle' => 2],
                ['nodeId' => $sineNodeId, 'clientHandle' => 3],
            ]);

            expect($monResults)->toHaveCount(3);
            foreach ($monResults as $result) {
                expect(StatusCode::isGood($result->statusCode))->toBeTrue();
                expect($result->monitoredItemId)->toBeInt()->toBeGreaterThan(0);
            }

            // Cleanup
            $monIds = array_map(fn($r) => $r->monitoredItemId, $monResults);
            $client->deleteMonitoredItems($subId, $monIds);
            $client->deleteSubscription($subId);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
