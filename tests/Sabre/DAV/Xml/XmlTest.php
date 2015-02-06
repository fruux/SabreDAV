<?php

namespace Sabre\DAV\Xml;

use Sabre\Xml\Writer;

abstract class XmlTest extends \PHPUnit_Framework_TestCase {

    protected $namespaceMap = ['DAV:' => 'd'];

    function write($input) {

        $writer = new Writer();
        $writer->baseUri = '/';
        $writer->namespaceMap = $this->namespaceMap;
        $writer->openMemory();
        $writer->write($input);
        return $writer->outputMemory();

    }

}
