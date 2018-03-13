<?php declare (strict_types=1);

namespace Sabre\DAV;

use GuzzleHttp\Psr7\ServerRequest;
use Sabre\DAVServerTest;
use Sabre\HTTP;

/**
 * Tests related to the MOVE request.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class HttpMoveTest extends DAVServerTest {

    /**
     * Sets up the DAV tree.
     *
     * @return void
     */
    function setUpTree() {

        $this->tree = new Mock\Collection('root', [
            'file1' => 'content1',
            'file2' => 'content2',
        ]);

    }

    function testMoveToSelf() {

        $request = new ServerRequest('MOVE', '/file1', [
            'Destination' => '/file1'
        ]);
        $response = $this->request($request);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('content1', $this->tree->getChild('file1')->get());

    }

    function testMove() {

        $request = new ServerRequest('MOVE', '/file1', [
            'Destination' => '/file3'
        ]);
        $response = $this->request($request);
        $this->assertEquals(201, $response->getStatusCode(), print_r($response, true));
        $this->assertEquals('content1', $this->tree->getChild('file3')->get());
        $this->assertFalse($this->tree->childExists('file1'));

    }

    function testMoveToExisting() {

        $request = new ServerRequest('MOVE', '/file1', [
            'Destination' => '/file2'
        ]);
        $response = $this->request($request);
        $this->assertEquals(204, $response->getStatusCode(), print_r($response, true));
        $this->assertEquals('content1', $this->tree->getChild('file2')->get());
        $this->assertFalse($this->tree->childExists('file1'));

    }

    function testMoveToExistingOverwriteT() {

        $request = new ServerRequest('MOVE', '/file1', [
            'Destination' => '/file2',
            'Overwrite'   => 'T',
        ]);
        $response = $this->request($request);
        $this->assertEquals(204, $response->getStatusCode(), print_r($response, true));
        $this->assertEquals('content1', $this->tree->getChild('file2')->get());
        $this->assertFalse($this->tree->childExists('file1'));

    }

    function testMoveToExistingOverwriteF() {

        $request = new ServerRequest('MOVE', '/file1', [
            'Destination' => '/file2',
            'Overwrite'   => 'F',
        ]);
        $response = $this->request($request);
        $this->assertEquals(412, $response->getStatusCode(), print_r($response, true));
        $this->assertEquals('content1', $this->tree->getChild('file1')->get());
        $this->assertEquals('content2', $this->tree->getChild('file2')->get());
        $this->assertTrue($this->tree->childExists('file1'));
        $this->assertTrue($this->tree->childExists('file2'));

    }

    /**
     * If we MOVE to an existing file, but a plugin prevents the original from
     * being deleted, we need to make sure that the server does not delete
     * the destination.
     */
    function testMoveToExistingBlockedDeleteSource() {

        $this->server->on('beforeUnbind', function($path) {

            if ($path === 'file1') {
                throw new \Sabre\DAV\Exception\Forbidden('uh oh');
            }

        });
        $request = new ServerRequest('MOVE', '/file1', [
            'Destination' => '/file2'
        ]);
        $response = $this->request($request);
        $this->assertEquals(403, $response->getStatusCode(), print_r($response, true));
        $this->assertEquals('content1', $this->tree->getChild('file1')->get());
        $this->assertEquals('content2', $this->tree->getChild('file2')->get());
        $this->assertTrue($this->tree->childExists('file1'));
        $this->assertTrue($this->tree->childExists('file2'));

    }
}
