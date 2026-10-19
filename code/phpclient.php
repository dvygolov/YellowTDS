<?php
/**
 * YellowTDS PHP Client Library
 * 
 * Simple include file for connecting external PHP sites to YellowTDS.
 * Just include this file in your index.php like:
 * require_once __DIR__ . '/phpclient.php';
 */

//if called directly return 404, haha
if (__FILE__ === $_SERVER['SCRIPT_FILENAME'] ||
    //fix for apache multiviews or php dev server
    $_SERVER['SCRIPT_NAME'] !== $_SERVER['PHP_SELF']) 
{
    http_response_code(404);
    exit;
}

define("YTDS_API_KEY", "test");
define("YTDS_API_URL", "http://localhost:8080/api/phpconnect.php");
define("YTDS_DEBUG", true);

class YellowTDSClient
{
    public function __construct()
    {
        ob_start();
        $this->requestClientHints();
    }

    public function connect()
    {
        $params = $this->collectParams();
        $response = $this->sendRequest($params);
        return $response;
    }
    
    public function process($response) 
    {
        if (!$response || !isset($response['action'])) 
            return;
        
        $this->logdebug("Got response: " . json_encode($response));
        switch ($response['action']) {
            case 'jscheck':
            case 'html':
                ob_clean();
                echo base64_decode($response['value']);
                exit;
            case 'redirect':
                ob_clean();
                $redirect_type = $response['redirect_type'] ?? 302;
                http_response_code($redirect_type);
                header("Referrer-Policy: no-referrer");
                header('Location: ' . $response['value']);
                exit;
            default:
                break;
        }
    }
    
    public function check() 
    {
        $response = $this->connect();
        $this->process($response);
    }
    
    private function collectParams() 
    {
        $params = [
            'api_key' => YTDS_API_KEY,
            'tds_ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'tds_ref' => $_SERVER['HTTP_REFERER'] ?? '',
            'tds_url' => $_SERVER['REQUEST_URI'] ?? '/',
            'tds_qs' => $_SERVER['QUERY_STRING'] ?? '',
            'tds_host' => $_SERVER['HTTP_HOST'] ?? '',
            'tds_lang' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        ];

        $params['tds_ip']=[];
        $params['tds_ip']['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']))
            $params['tds_ip']['HTTP_CF_CONNECTING_IP'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $params['tds_ip']['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
        
        //adding client hints if they are present
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_SEC_CH_UA_') === 0) {
                if (!array_key_exists('tds_client_hints', $params))
                    $params['tds_client_hints'] = [];
                $params['tds_client_hints'][$key] = $value;
            }
        }
        
        return $params;
    }
    
    private function sendRequest($params) 
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => YTDS_API_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: YellowTDS-PHP-Client/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => false
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        if ($curl_error) {
            $this->log("YellowTDS cURL Error: " . $curl_error);
            return null;
        }
        
        if ($http_code !== 200) {
            $this->log("YellowTDS HTTP Error: " . $http_code);
            return null;
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("YellowTDS JSON Error: " . json_last_error_msg(). "\n" . $response);
            return null;
        }
        
        return $decoded;
    }
    
    private function getip():string
    {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']))
            return $_SERVER['HTTP_CF_CONNECTING_IP'];

        return $_SERVER['REMOTE_ADDR'];
    }

    private function requestClientHints(): void
    {
        $headers = [
            'Sec-CH-UA', 'Sec-CH-UA-Arch', 'Sec-CH-UA-Bitness',
            'Sec-CH-UA-Full-Version', 'Sec-CH-UA-Full-Version-List',
            'Sec-CH-UA-Mobile', 'Sec-CH-UA-Platform', 'Sec-CH-UA-Platform-Version',
            'Sec-CH-UA-WoW64', 'Sec-CH-UA-Model'
        ];
        $headers_str = implode(', ', $headers);
        header('Accept-CH: ' . $headers_str, true);
        header('Critical-CH: ' . $headers_str, true);
        header('Vary: ' . $headers_str, true);
    }

    private function log($msg, $addIp = false)
    {
        $dir = __DIR__ . "/ycclogs";
        if (!file_exists($dir)) 
            mkdir($dir, 0777, true);
        $datetime = explode(' ', date("d.m.y H:i:s"));
        $date = $datetime[0];  // "d.m.y"
        $time = $datetime[1];  // "H:i:s"
        $fileName = "$dir/$date.log";
        if ($addIp) {
            $ip = $this->getip();
            $time .= " $ip";
        }
        $msg = "$time $msg\n";
        file_put_contents($fileName, $msg, FILE_APPEND | LOCK_EX);
    }
    
    private function logdebug($msg){
        if (!YTDS_DEBUG) return;
        file_put_contents("php://stdout", $msg);
    }

        
}

$ytds = new YellowTDSClient();
register_shutdown_function([$ytds, 'check']);
