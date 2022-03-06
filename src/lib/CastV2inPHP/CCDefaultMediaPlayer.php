<?php
namespace App\lib\CastV2inPHP;

// Make it really easy to play videos by providing functions for the Chromecast Default Media Player

class CCDefaultMediaPlayer extends CCBaseSender
{
	public $appid="CC1AD845";
	
	public function play($url,$streamType,$contentType,$autoPlay,$currentTime,$metadata = []) {
		// Start a playing
                if (!isset($metadata['title'])) {
                    $metadata['title'] = '';
                }
                if (!isset($metadata['subtitle'])) {
                    $metadata['subtitle'] = 'Powered by OSHANS.ch';
                }
                if (!isset($metadata['image'])) {
                    $metadata['image'] = '';
                }
		// First ensure there's an instance of the DMP running
		if ($success = $this->launch()) {
                    $json = '{"type":"LOAD","media":{"metadata":{"metadataType":0,"title":"'.$metadata['title'].'","subtitle":"'.$metadata['subtitle'].'","images":{"0":{"url":"'.$metadata['image'].'"}}},"contentId":"' . $url . '","streamType":"' . $streamType . '","contentType":"' . $contentType . '"},"autoplay":' . $autoPlay . ',"currentTime":' . $currentTime . ',"requestId":921489134}';
                    $this->chromecast->sendMessage("urn:x-cast:com.google.cast.media", $json);
                    $r = "";
                    $counter = 10;
                    while ($counter > 0 && !preg_match("/\"playerState\":\"PLAYING\"/",$r)) {
                            $counter--;
                            $r = $this->chromecast->getCastMessage();
                            sleep(1);
                    }
                    // Grab the mediaSessionId
                    preg_match("/\"mediaSessionId\":([^\,]*)/",$r,$m);
                    $this->mediaid = $m[1];
                }

                return $success;
	}
	
	public function pause() {
		// Pause
		if ($success = $this->launch()) { // Auto-reconnects
                    $this->chromecast->sendMessage("urn:x-cast:com.google.cast.media",'{"type":"PAUSE", "mediaSessionId":' . $this->mediaid . ', "requestId":1}');
                    $this->chromecast->getCastMessage();
                }

                return $success;
	}

	public function restart() {
		// Restart (after pause)
		$this->launch(); // Auto-reconnects
		$this->chromecast->sendMessage("urn:x-cast:com.google.cast.media",'{"type":"PLAY", "mediaSessionId":' . $this->mediaid . ', "requestId":1}');
		$this->chromecast->getCastMessage();
	}
	
	public function seek($secs) {
		// Seek
		$this->launch(); // Auto-reconnects
		$this->chromecast->sendMessage("urn:x-cast:com.google.cast.media",'{"type":"SEEK", "mediaSessionId":' . $this->mediaid . ', "currentTime":' . $secs . ',"requestId":1}');
		$this->chromecast->getCastMessage();
	}
	
	public function stop() {
		// Stop
		if ($success = $this->launch()) { // Auto-reconnects
                    $this->chromecast->sendMessage("urn:x-cast:com.google.cast.media",'{"type":"STOP", "mediaSessionId":' . $this->mediaid . ', "requestId":1}');
                    $this->chromecast->getCastMessage();
                }

                return $success;
	}
	
	public function getStatus() {
		// Stop
		$this->launch(); // Auto-reconnects
		$this->chromecast->sendMessage("urn:x-cast:com.google.cast.media",'{"type":"GET_STATUS", "mediaSessionId":' . $this->mediaid . ', "requestId":1}');
		$r = $this->chromecast->getCastMessage();
		preg_match("/{\"type.*/",$r,$m);
		return json_decode($m[0]);
	}
	
	public function Mute() {
		// Mute a video
		$this->launch(); // Auto-reconnects
		$this->chromecast->sendMessage("urn:x-cast:com.google.cast.receiver", '{"type":"SET_VOLUME", "volume": { "muted": true }, "requestId":1 }');
		$this->chromecast->getCastMessage();
	}
	
	public function UnMute() {
		// Mute a video
		$this->launch(); // Auto-reconnects
		$this->chromecast->sendMessage("urn:x-cast:com.google.cast.receiver", '{"type":"SET_VOLUME", "volume": { "muted": false }, "requestId":1 }');
		$this->chromecast->getCastMessage();
	}
	
	public function SetVolume($volume) {
		// Mute a video
		if ($success = $this->launch()) { // Auto-reconnects
                    $this->chromecast->sendMessage("urn:x-cast:com.google.cast.receiver", '{"type":"SET_VOLUME", "volume": { "level": ' . $volume . ' }, "requestId":1 }');
                    $this->chromecast->getCastMessage();
                }

                return $success;
	}
}
