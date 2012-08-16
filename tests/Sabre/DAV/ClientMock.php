<?php

class Sabre_DAV_ClientMock extends Sabre_DAV_Client {

    public $response;

    public $curlSettings;
	public function __construct(array $settings) {
		$this->curlSettings=$settings;
    }
    protected function curlRequest($curlSettings) {
        $this->curlSettings += $curlSettings;
        return $this->response;

    }

    /**
     * Just making this method public
     *
     * @param string $url
     * @return string
     */
    public function getAbsoluteUrl($url) {

        return parent::getAbsoluteUrl($url);

    }

}
