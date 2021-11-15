<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 *
 * @author Markus Schafroth
 */
class ThreemaConnector
{
    protected $em;
    protected $client;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->connectors = $connectors;
        if ($this->getAvailable()) {
            $this->config = $connectors['threema'];
        }
        $this->apiSendSimple = 'https://msgapi.threema.ch/send_simple';
    }

    public function getAvailable()
    {
        if (array_key_exists('threema', $this->connectors)) {
            return true;
        } else {
            return false;
        }
    }

    public function sendMessage($email, $msg)
    {
        $payload = [
            'from' => $this->config['id'],
            'email' => $email,
            'text' => $msg,
            'secret' => $this->config['secret'],
        ];

        $response = $this->client->request(
                'POST',
                $this->apiSendSimple,
                [
                    'body' => $payload
                ]
            );
    }
}
