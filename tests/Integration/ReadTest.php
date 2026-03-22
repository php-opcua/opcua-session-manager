<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Read via ManagedClient', function () {

    // -- Scalar reads ----

    describe('Scalar', function () {

        it('reads BooleanValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeBool();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads Int32Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads DoubleValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'DoubleValue']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads StringValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeString();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads FloatValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'FloatValue']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads ByteValue', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'ByteValue']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads UInt16Value', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'UInt16Value']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeInt();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // -- Multi-read --

    describe('ReadMulti', function () {

        it('reads multiple nodes in one call', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $boolNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);
                $intNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);
                $strNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);

                $results = $client->readMulti([
                    ['nodeId' => $boolNodeId],
                    ['nodeId' => $intNodeId],
                    ['nodeId' => $strNodeId],
                ]);

                expect($results)->toHaveCount(3);
                expect($results[0]->statusCode)->toBe(StatusCode::Good);
                expect($results[0]->getValue())->toBeBool();
                expect($results[1]->statusCode)->toBe(StatusCode::Good);
                expect($results[1]->getValue())->toBeInt();
                expect($results[2]->statusCode)->toBe(StatusCode::Good);
                expect($results[2]->getValue())->toBeString();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // -- Server State --

    describe('Server State', function () {

        it('reads ServerState (Running = 0)', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $dv = $client->read(NodeId::numeric(0, 2259));
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBe(0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // -- Array reads --

    describe('Array', function () {

        it('reads Int32Array', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'Int32Array']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

        it('reads StringArray', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Array', 'StringArray']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeArray()->not->toBeEmpty();
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

    // -- WithRange (AnalogDataItems) --

    describe('WithRange', function () {

        it('reads Temperature', function () {
            $client = null;
            try {
                $client = TestHelper::connectNoSecurity();
                $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'WithRange', 'Temperature']);
                $dv = $client->read($nodeId);
                expect($dv->statusCode)->toBe(StatusCode::Good);
                expect($dv->getValue())->toBeFloat();
                expect($dv->getValue())->toBeGreaterThan(20.0)->toBeLessThan(25.0);
            } finally {
                TestHelper::safeDisconnect($client);
            }
        })->group('integration');

    });

})->group('integration');
