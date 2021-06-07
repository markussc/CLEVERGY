<?php

namespace App\Utils\Connectors;

use Nesk\Puphpeteer\Puppeteer;

/**
 * Connector to retrieve data from the WeConnectID API (Volkswagen)
 * Note: requires the following prerequisites installed on the system. Ubuntu: <code>composer require nesk/puphpeteer; npm install @nesk/puphpeteer</code>
 *
 * @author Markus Schafroth
 */
class WeConnectIdConnector
{
    protected $em;
    private $basePath;
    private $loginPath;
    private $page;
    private $username;
    private $password;
    private $carId;

    public function __construct(Array $config = [])
    {
        if (is_array($config) && array_key_exists('username', $config) && array_key_exists('password', $config) && array_key_exists('carId', $config)) {
            $this->username = $config['username'];
            $this->password = $config['password'];
            $this->carId = $config['carId'];
            $this->basePath = 'https://www.volkswagen.de/de/besitzer-und-nutzer/myvolkswagen/garage.html?---=%7B%22besitzer-und-nutzer_myvolkswagen_garage_featureAppSection%22%3A%22%2Fdetail%2FWVWZZZE1ZMP076007%22%7D';
            $this->loginPath = 'https://www.volkswagen.de/app/authproxy/login?fag=vw-de,vwag-weconnect&scope-vw-de=profile,address,phone,carConfigurations,dealers,cars,vin,profession&scope-vwag-weconnect=openid&prompt-vw-de=login&prompt-vwag-weconnect=none&redirectUrl=https://www.volkswagen.de/de/besitzer-und-nutzer/myvolkswagen/garage.html';
        }
    }

    /*
     * authenticates with user credentials
     */
    private function authenticate()
    {
        // initialize
        $puppeteer = new Puppeteer;
        $this->browser = $puppeteer->launch();
        $this->page = $this->browser->newPage();

        // authenticate
        $this->page->goto($this->loginPath);
        $this->page->waitForSelector("#input_email");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#input_email").value = "' . $this->username.'";
                })()'
        );
        $this->page->evaluate(
            '(() => {
                    $("#emailPasswordForm").submit();
                })()'
        );
        $this->page->waitForSelector("#credentialsForm");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#password").value = "' . $this->password.'";
                })()'
        );
        $this->page->evaluate(
            '(() => {
                    $("#credentialsForm").submit();
                })()'
        );
    }

    /*
     * try to get as much data as possible from the overview page
     */
    public function getData()
    {
        $data = null;
        try {
            $this->authenticate();

            // go to start page
            $this->page->waitForSelector(".iCPZSx > div:nth-child(1) > div:nth-child(1) > div:nth-child(2) > div:nth-child(2) > section:nth-child(1) > svg:nth-child(2) > g:nth-child(1) > text:nth-child(3) > tspan:nth-child(1)");

            // find cruisingRange
            $data['properties'][1]['value'] = str_replace(' km', '', $this->page->evaluate('document.querySelector(".iCPZSx > div:nth-child(1) > div:nth-child(1) > div:nth-child(2) > div:nth-child(2) > section:nth-child(1) > svg:nth-child(2) > g:nth-child(1) > text:nth-child(3) > tspan:nth-child(1)").innerHTML'));
        } catch (\Exception $e) {
            // do nothing
        }
        $this->close();

        return $data;
    }

    /*
     * close the browser
     */
    public function close()
    {
        try {
            $this->browser->close();
            $this->browser = null;
        } catch (\Exception $e) {
            // do nothing
        }
    }
}