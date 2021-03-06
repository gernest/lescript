<?php

class Lescript
{
    public $ca = 'https://acme-v01.api.letsencrypt.org';
    // public $ca = 'https://acme-staging.api.letsencrypt.org'; // testing
    public $license = 'https://letsencrypt.org/documents/LE-SA-v1.0.1-July-27-2015.pdf';
    public $country_code = 'CZ';
    public $state = "Czech Republic";

    private $certificates_dir;
    private $web_root_dir;
    private $logger;
    private $client;
    private $account_key_path;

    public function __construct($certificates_dir, $web_root_dir, $logger)
    {
        $this->certificates_dir = $certificates_dir;
        $this->web_root_dir = $web_root_dir;
        $this->logger = $logger;
        $this->client = new Client($this->ca);
        $this->account_key_path = $certificates_dir.'/_account/private.pem';
    }

    public function initAccount()
    {
        $this->logger->info('Starting new account registration');

        // generate and save new private key for account
        // ---------------------------------------------

        if(!is_file($this->account_key_path)) {
            $this->generateKey(dirname($this->account_key_path));
        }

        // send registration
        // -----------------

        $this->postNewReg();

        $this->logger->info('New account certificate registered');
    }

    public function signDomains($domains)
    {
        $this->logger->info('Starting certificate generation process for domains');

        if(($privateAccountKey = openssl_pkey_get_private('file://'.$this->account_key_path)) === FALSE) {
            throw new \RuntimeException(openssl_error_string());
        }
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        // start domains authentication
        // ----------------------------

        foreach($domains as $domain) {

            // 1. getting available authentication options
            // -------------------------------------------

            $this->logger->info("Requesting challenge for $domain");

            $response = $this->signedRequest(
                "/acme/new-authz",
                array("resource" => "new-authz", "identifier" => array("type" => "dns", "value" => $domain))
            );

            // choose http-01 challange only
            $challenge = array_reduce($response['challenges'], function($v, $w) { return $v ? $v : ($w['type'] == 'http-01' ? $w : false); });
            if(!$challenge) throw new \RuntimeException("HTTP Challenge for $domain is not available");

            $this->logger->info("Got challenge token for $domain");
            $location = $this->client->getLastLocation();


            // 2. saving authentication token for web verification
            // ---------------------------------------------------

            $directory = $this->web_root_dir.'/.well-known/acme-challenge';
            $token_path = $directory.'/'.$challenge['token'];

            if(!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Couldn't create directory to expose challenge: ${token_path}");
            }

            $header = array(
                // need to be in precise order!
                "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

            );
            $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

            file_put_contents($token_path, $payload);
            chmod($token_path, 0644);

            // 3. verification process itself
            // -------------------------------

            $uri = "http://${domain}/.well-known/acme-challenge/${challenge['token']}";

            $this->logger->info("Token for $domain saved at $token_path and should be available at $uri");

            // simple self check
            if($payload !== trim(@file_get_contents($uri))) {
                throw new \RuntimeException("Please check $uri - token not available");
            }

            $this->logger->info("Sending request to challenge");

            // send request to challenge
            $result = $this->signedRequest(
                $challenge['uri'],
                array(
                    "resource" => "challenge",
                    "type" => "http-01",
                    "keyAuthorization" => $payload,
                    "token" => $challenge['token']
                )
            );

            // waiting loop
            do {
                if(empty($result['status']) || $result['status'] == "invalid") {
                    throw new \RuntimeException("Verification ended with error: ".json_encode($result));
                }
                $ended = !($result['status'] === "pending");

                if(!$ended) {
                    $this->logger->info("Verification pending, sleeping 1s");
                    sleep(1);
                }

                $result = $this->client->get($location);

            } while (!$ended);

            $this->logger->info("Verification ended with status: ${result['status']}");
            @unlink($token_path);
        }

        // requesting certificate
        // ----------------------
        $domainPath = $this->getDomainPath(reset($domains));

        // generate private key for domain if not exist
        if(!is_dir($domainPath)) {
            $this->generateKey($domainPath);
        }

        // load domain key
        if(($privateDomainKey = openssl_pkey_get_private('file://'.$domainPath.'/private.pem')) === FALSE) {
            throw new \RuntimeException(openssl_error_string());
        }

        $this->client->getLastLinks();

        // request certificates creation
        $result = $this->signedRequest(
            "/acme/new-cert",
            array('resource' => 'new-cert', 'csr' => $this->generateCSR($privateDomainKey, $domains))
        );
        if ($this->client->getLastCode() !== 201) {
            throw new \RuntimeException("Invalid response code: ".$this->client->getLastCode().", ".json_encode($result));
        }
        $location = $this->client->getLastLocation();

        // waiting loop
        $certificates = array();
        while(1) {
            $this->client->getLastLinks();

            $result = $this->client->get($location);

            if($this->client->getLastCode() == 202) {

                $this->logger->info("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else if ($this->client->getLastCode() == 200) {

                $this->logger->info("Got certificate! YAY!");
                $certificates[] = $this->parsePemFromBody($result);


                foreach($this->client->getLastLinks() as $link) {
                    $this->logger->info("Requesting chained cert at $link");
                    $result = $this->client->get($link);
                    $certificates[] = $this->parsePemFromBody($result);
                }

                break;
            } else {

                throw new \RuntimeException("Can't get certificate: HTTP code ".$this->client->getLastCode());

            }
        }

        if(empty($certificates)) throw new \RuntimeException('No certificates generated');

        $this->logger->info("Saving fullchain.pem");
        file_put_contents($domainPath.'/fullchain.pem', implode("\n", $certificates));

        $this->logger->info("Saving cert.pem");
        file_put_contents($domainPath.'/cert.pem', array_shift($certificates));

        $this->logger->info("Saving chain.pem");
        file_put_contents($domainPath."/chain.pem", implode("\n", $certificates));

        $this->logger->info("Done !!§§!");
    }

    private function parsePemFromBody($body)
    {
        $pem = chunk_split(base64_encode($body), 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . $pem . "-----END CERTIFICATE-----\n";
    }

    private function getDomainPath($domain)
    {
        return $this->certificates_dir.'/'.$domain.'/';
    }

    private  function postNewReg()
    {
        $this->logger->info('Sending registration to letsencrypt server');

        return $this->signedRequest(
            '/acme/new-reg',
            array('resource' => 'new-reg', 'agreement' => $this->license)
        );
    }

    private function generateCSR($privateKey, array $domains)
    {
        $domain = reset($domains);
        $san = implode(",", array_map(function ($dns) { return "DNS:" . $dns; }, $domains));
        $tmpConf = tmpfile();
        $tmpConfMeta =  stream_get_meta_data($tmpConf);
        $tmpConfPath = $tmpConfMeta["uri"];

        // workaround to get SAN working
        fwrite($tmpConf,
'HOME = .
RANDFILE = $ENV::HOME/.rnd
[ req ]
default_bits = 2048
default_keyfile = privkey.pem
distinguished_name = req_distinguished_name
req_extensions = v3_req
[ req_distinguished_name ]
countryName = Country Name (2 letter code)
[ v3_req ]
basicConstraints = CA:FALSE
subjectAltName = '.$san.'
keyUsage = nonRepudiation, digitalSignature, keyEncipherment');

        $csr = openssl_csr_new(
            array(
                "CN" => $domain,
                "ST" => $this->state,
                "C" => $this->country_code,
                "O" => "Unknown",
            ),
            $privateKey,
            array(
                "config" => $tmpConfPath,
                "digest_alg" => "sha256"
            )
        );

        if (!$csr) throw new \RuntimeException("CSR couldn't be generated! ".openssl_error_string());

        openssl_csr_export($csr, $csr);
        fclose($tmpConf);

        file_put_contents($this->getDomainPath($domain)."/last.csr", $csr);
        preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

        return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
    }

    private function generateKey($output_directory)
    {
        $res = openssl_pkey_new(array(
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "private_key_bits" => 4096,
        ));

        if(!openssl_pkey_export($res, $privateKey)) {
            throw new \RuntimeException("Key export failed!");
        }

        $details = openssl_pkey_get_details($res);

        if(!is_dir($output_directory)) @mkdir($output_directory, 0700, true);
        if(!is_dir($output_directory)) throw new \RuntimeException("Cant't create directory $output_directory");

        file_put_contents($output_directory.'/private.pem', $privateKey);
        file_put_contents($output_directory.'/public.pem', $details['key']);
    }

    private function signedRequest($uri, array $payload) {

        if(($privateKey = openssl_pkey_get_private('file://'.$this->account_key_path)) === FALSE) {
            throw new \RuntimeException(openssl_error_string());
        }

        $details = openssl_pkey_get_details($privateKey);

        $header = array(
            "alg" => "RS256",
            "jwk" => array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            )
        );

        $protected = $header;
        $protected["nonce"] = $this->client->getLastNonce();


        $payload64 = Base64UrlSafeEncoder::encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, OPENSSL_ALGO_SHA256);

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'header' => $header,
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->logger->info("Sending signed request to $uri");

        return $this->client->post($uri, json_encode($data));
    }
}

class Client
{
    private $last_code;
    private $last_header;

    private $base;

    public function __construct($base)
    {
        $this->base = $base;
    }

    private function curl($method, $url, $data = null)
    {
        $headers = array('Accept: application/json', 'Content-Type: application/json');
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, preg_match('~^http~', $url) ? $url : $this->base.$url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, true);

        // DO NOT DO THAT!
        // curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
        // curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
                break;
            case 'HEAD':
                curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'HEAD');
                break;
        }
        $response = curl_exec($handle);

        if(curl_errno($handle)) {
            throw new \RuntimeException('Curl: '.curl_error($handle));
        }

        $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        $this->last_header = $header;
        $this->last_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        $data = json_decode($body, true);
        return $data === null ? $body : $data;
    }

    public function post($url, $data)
    {
        return $this->curl('POST', $url, $data);
    }

    public function get($url)
    {
        return $this->curl('GET', $url);
    }

    public function getLastNonce()
    {
        if(preg_match('~Replay\-Nonce: (.+)~i', $this->last_header, $matches)) {
            return trim($matches[1]);
        }

        $this->curl('HEAD', '/directory');
        return $this->getLastNonce();
    }

    public function getLastLocation()
    {
        if(preg_match('~Location: (.+)~i', $this->last_header, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function getLastCode()
    {
        return $this->last_code;
    }

    public function getLastLinks()
    {
        preg_match_all('~Link: <(.+)>;rel="up"~', $this->last_header, $matches);
        return $matches[1];
    }
}

class Base64UrlSafeEncoder
{
    public static function encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    public static function decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
