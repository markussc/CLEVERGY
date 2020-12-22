<?php

namespace App\Utils\Connectors;

use App\Entity\Settings;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to retrieve data from the WEM Portal (Weishaupt Energy Manager)
 *
 * @author Markus Schafroth
 */
class WemConnector
{
    const MODE_SUMMER = 0;
    const MODE_AUTO = 1;
    const MODE_HOLIDAY = 2;
    const MODE_PARTY = 3;
    const MODE_2ND = 4;
    protected $em;
    private $basePath;
    private $client;
    private $cookies;
    private $username;
    private $password;

    public function __construct(EntityManager $em, HttpClientInterface $client, Array $connectors, $browser)
    {
        if (array_key_exists('wem', $connectors)) {
            $this->em = $em;
            $this->client = $client;
            $this->basePath = "https://www.wemportal.com/Web/";
            $this->username = $connectors['wem']['username'];
            $this->password = $connectors['wem']['password'];
            $this->authenticate();
        } else {
            $this->username = null;
            $this->password = null;
        }
    }

    public function getAllLatest()
    {
        return $this->em->getRepository('App:WemDataStore')->getLatest($this->username);
    }

    public function getAll()
    {
        // get analog, digital and integer values
        try {
            $defaultData = $this->getDefault();
            return array_merge($defaultData); // prepared for further separate queries to detail pages
        } catch (\Exception $e) {
          return false;
        }
    }

    public function getUsername()
    {
        return $this->username;
    }

    private function statusToString($status)
    {
        if ($status === 'Ein') {
            return 'label.device.status.on';
        } else {
            return 'label.device.status.off';
        }
    }

    private function authenticate()
    {
        try {
            $response = $this->client->request('GET', $this->basePath . 'Login.aspx?AspxAutoDetectCookieSupport=1');

            if ($response->getStatusCode() === Response::HTTP_OK) {
                $formFields = $this->parseLoginForm($response->getContent());
                $formFields['ctl00$content$tbxUserName'] = $this->username;
                $formFields['ctl00$content$tbxPassword'] = $this->password;
                $formFields['ctl00$content$btnLogin'] = 'Anmelden';
                $cookies = $response->getHeaders()['set-cookie'];
                $responseAuth = $this->client->request(
                    'POST',
                    $this->basePath . 'Login.aspx',
                    [
                        'headers' => ['Cookie' => $cookies],
                        'body' => $formFields,
                        'max_redirects' => 0,
                    ]
                );

                if ($responseAuth->getStatusCode() === Response::HTTP_OK || $responseAuth->getStatusCode() === Response::HTTP_FOUND) {
                    foreach ($responseAuth->getInfo('response_headers') as $header) {
                        $splitHeader = explode('Set-Cookie: ', $header);
                        if (count($splitHeader) >  1) {
                            $cookies[] = $splitHeader[1];
                            break;
                        }
                    }
                    foreach ($cookies as $key=>$val) {
                        $cookies[$key] = explode(';', $val)[0];
                    }
                    array_unshift($cookies, 'AspxAutoDetectCookieSupport=1');
                    $this->cookies = join('; ', $cookies);
                }
            }
        } catch (\Exception $e) {
            $this->cookies = null;
        }
    }

    private function parseLoginForm($html)
    {
        $formFields = [];
        $crawler = new Crawler($html);
        foreach ($crawler->filter('input') as $inputElement) {
            $formFields[$inputElement->getAttribute('id')] = $inputElement->getAttribute('value');
        }

        return $formFields;
    }

    private function getDefault()
    {
        $response = $this->client->request(
            'GET',
            $this->basePath . 'Default.aspx',
            [
                'headers' => ['Cookie' => $this->cookies],
            ]
        );

        $data = [];
        $crawler = new Crawler($response->getContent());
        $data['waterTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue')->text())[0];
        $data['storTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl3_ctl00_lblValue')->text())[0];
        $data['outsideTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue')->text())[0];
        $data['setDistrTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl4_ctl00_lblValue')->text())[0];
        $data['effDistrTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue')->text())[0];
        $data['preTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue')->text())[0];
        $data['backTemp'] = explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue')->text())[0];
        $data['cpStatus'] = $this->statusToString(explode(' ', $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl3_ctl00_lblValue')->text())[0]);
        $data['ppStatus'] = $crawler->filter('#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl1_ctl00_lblValue')->text();

        return $data;
    }
}
