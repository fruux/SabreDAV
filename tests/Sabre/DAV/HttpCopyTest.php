<?php declare (strict_types=1);

namespace Sabre\DAV;

use GuzzleHttp\Psr7\ServerRequest;
use Sabre\DAVServerTest;
use Sabre\HTTP;

/**
 * Tests related to the COPY request.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class HttpCopyTest extends DAVServerTest {

    /**
     * Sets up the DAV tree.
     *
     * @return void
     */
    function setUpTree() {

        $this->tree = new Mock\Collection('root', [
            'file1' => 'content1',
            'file2' => 'content2',
            'coll1' => [
                'file3' => 'content3',
                'file4' => 'content4',
            ]
        ]);

    }
    
    function testCopyFile() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file5'
        ]);
        $response = $this->request($request);
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('content1', $this->tree->getChild('file5')->get());

    }

    function testCopyFileToSelf() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file1'
        ]);
        $response = $this->request($request);
        $this->assertEquals(403, $response->getStatusCode());

    }

    function testCopyFileToExisting() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file2'
        ]);
        $response = $this->request($request);
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('content1', $this->tree->getChild('file2')->get());

    }

    function testCopyFileToExistingOverwriteT() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file2',
            'Overwrite'   => 'T',
        ]);
        $response = $this->request($request);
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('content1', $this->tree->getChild('file2')->get());

    }
   
    function testCopyFileToExistingOverwriteBadValue() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file2',
            'Overwrite'   => 'B',
        ]);
        $response = $this->request($request);
        $this->assertEquals(400, $response->getStatusCode());

    }

    function testCopyFileNonExistantParent() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/notfound/file2',
        ]);
        $response = $this->request($request);
        $this->assertEquals(409, $response->getStatusCode());

    }

    function testCopyFileToExistingOverwriteF() {

        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file2',
            'Overwrite'   => 'F',
        ]);
        $response = $this->request($request);
        $this->assertEquals(412, $response->getStatusCode());
        $this->assertEquals('content2', $this->tree->getChild('file2')->get());

    }

    function testCopyFileToExistinBlockedCreateDestination() {

        $this->server->on('beforeBind', function($path) {

            if ($path === 'file2') {
                return false;
            }

        });
        $request = new ServerRequest('COPY', '/file1', [
            'Destination' => '/file2',
            'Overwrite'   => 'T',
        ]);
        $response = $this->request($request);

        // This checks if the destination file is intact.
        $this->assertEquals('content2', $this->tree->getChild('file2')->get());

    }

    function testCopyColl() {

        $request = new ServerRequest('COPY', '/coll1', [
            'Destination' => '/coll2'
        ]);
        $response = $this->request($request);
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('content3', $this->tree->getChild('coll2')->getChild('file3')->get());

    }

    function testCopyCollToSelf() {

        $request = new ServerRequest('COPY', '/coll1', [
            'Destination' => '/coll1'
        ]);
        $response = $this->request($request);
        $this->assertEquals(403, $response->getStatusCode());

    }

    function testCopyCollToExisting() {

        $request = new ServerRequest('COPY', '/coll1', [
            'Destination' => '/file2'
        ]);
        $response = $this->request($request);
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('content3', $this->tree->getChild('file2')->getChild('file3')->get());

    }

    function testCopyCollToExistingOverwriteT() {

        $request = new ServerRequest('COPY', '/coll1', [
            'Destination' => '/file2',
            'Overwrite'   => 'T',
        ]);
        $response = $this->request($request);
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('content3', $this->tree->getChild('file2')->getChild('file3')->get());

    }

    function testCopyCollToExistingOverwriteF() {

        $request = new ServerRequest('COPY', '/coll1', [
            'Destination' => '/file2',
            'Overwrite'   => 'F',
        ]);
        $response = $this->request($request);
        $this->assertEquals(412, $response->getStatusCode());
        $this->assertEquals('content2', $this->tree->getChild('file2')->get());

    }

    function testCopyCollIntoSubtree() {

        $request = new ServerRequest('COPY', '/coll1', [
            'Destination' => '/coll1/subcol',
        ]);
        $response = $this->request($request);
        $this->assertEquals(409, $response->getStatusCode());

    }


}
