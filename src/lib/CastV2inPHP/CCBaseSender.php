<?php
namespace App\lib\CastV2inPHP;

// Base class to extend to provide functionality for different receivers

class CCBaseSender {
    public $chromecast; // The chromecast the initiated this instance.
    public $mediaid; // The media session id.

    public function __construct($hostchromecast) {
        $this->chromecast = $hostchromecast;
    }

    public function launch() {
        // Launch the player or connect to an existing instance if one is already running
        // First connect to the chromecast
        $this->chromecast->transportid = "";

        $this->chromecast->cc_connect();
        $s = $this->chromecast->getStatus();
        if (!$s) {
            // we were not able to retrieve a valid status
            return false;
        }
        // Grab the appid
        preg_match("/\"appId\":\"([^\"]*)/",$s,$m);
        $appid = '';
        if (is_array($m) && array_key_exists(1, $m)) {
            $appid = $m[1];
        }
        if ($appid == $this->appid) {
            // Default Media Receiver is live
            $this->chromecast->getStatus();
            $this->chromecast->connect();
        } else {
            // Default Media Receiver is not currently live, start it.
            $this->chromecast->launch($this->appid);
            $this->chromecast->transportid = "";
            $r = "";
            $counter = 15;
            while ($counter > 0  && !preg_match("/Ready to Cast/",$r) && !preg_match("/Default Media Receiver/",$r)) {
                $counter--;
                $r = $this->chromecast->getStatus();
                sleep(1);
            }
            $this->chromecast->connect();
        }

        return true;
    }
	
}
