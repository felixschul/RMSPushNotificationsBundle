<?php

namespace RMS\PushNotificationsBundle\Service\OS;

use Psr\Log\LoggerInterface;
use RMS\PushNotificationsBundle\Exception\InvalidMessageTypeException;
use RMS\PushNotificationsBundle\Message\WindowsMessage;
use RMS\PushNotificationsBundle\Message\WindowsphoneMessage;
use RMS\PushNotificationsBundle\Message\MessageInterface;
use Buzz\Browser,
    Buzz\Client\Curl;

class WindowsNotification implements OSNotificationServiceInterface
{
    /**
     * Browser object
     *
     * @var \Buzz\Browser
     */
    protected $browser;

    /**
     * Monolog logger
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * SID (Secure Identifier) as according to your app's entry in the windows dev center
     *
     * @var string
     */
    protected $sid;

    /**
     * Client secret according to your app's entry in the windows dev center
     *
     * @var string
     */
    protected $secret;

    /**
     * The system needs to request an oAuth access token to access WNS server. The token is requested once here and cached
     * within one session.
     *
     * @var string
     */
    protected $accessToken;

    /**
     * @param $timeout
     * @param $logger
     */
    public function __construct($sid, $secret, $timeout, $logger)
    {
        $this->browser = new Browser(new Curl());
        $this->browser->getClient()->setVerifyPeer(false);
        $this->browser->getClient()->setTimeout($timeout);
        $this->logger = $logger;
        $this->sid = $sid;
        $this->secret = $secret;
    }

    protected function getAccessToken()
    {
        if ($this->accessToken != '') {
            return;
        }

        $str = "grant_type=client_credentials&client_id=$this->sid&client_secret=$this->secret&scope=notify.windows.com";
        $url = "https://login.live.com/accesstoken.srf";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, "$str");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        $output = json_decode($output);
        if (isset($output->error)) {
            throw new \Exception($output->error_description);
        }
        $this->accessToken = $output->access_token;
    }

    protected function buildTileXml($title, $img)
    {
        return '<?xml version="1.0" encoding="utf-16"?>' .
        '<tile>' .
        '<visual lang="en-US">' .
        '<binding template="TileWideImageAndText01">' .
        '<image id="1" src="' . $img . '"/>' .
        '<text id="1">' . $title . '</text>' .
        '</binding>' .
        '</visual>' .
        '</tile>';
    }

    public function send(MessageInterface $message)
    {
        if (!$message instanceof WindowsMessage) {
            throw new InvalidMessageTypeException(sprintf("Message type '%s' not supported by WNS", get_class($message)));
        }

        if (!$this->accessToken) {
            $this->getAccessToken();
        }

        $xml = $this->buildTileXml("test", "");

        $headers = array('Content-Type: text/xml', "Content-Length: " . strlen($xml), "X-WNS-Type: $message->getType()", "Authorization: Bearer $this->accessToken");
//        if ($tileTag != '') {
//            array_push($headers, "X-WNS-Tag: $tileTag");
//        }
        $ch = curl_init($message->getDeviceIdentifier());
        # Tiles: http://msdn.microsoft.com/en-us/library/windows/apps/xaml/hh868263.aspx
        # http://msdn.microsoft.com/en-us/library/windows/apps/hh465435.aspx
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "$xml");
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        $response = curl_getinfo($ch);
        curl_close($ch);

        $code = $response['http_code'];
        if ($code == 200) {
            $this->logger->info("Message sent successfully");
            return true;
        } else if ($code == 401) {
            $this->accessToken = '';
            $this->logger->error($response->getStatusCode(). ' : '. $response->getReasonPhrase());
            return false;
        } else {
            $this->logger->error($response->getStatusCode(). ' : '. $response->getReasonPhrase());
            return false;
        }

    }
}
