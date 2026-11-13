<?php
 
/**
 * Sencrypt module: Lescript, a small ACME v2 (Let's Encrypt) client.
 *
 * Based on analogic/lescript, changed for Sentora (country code and state in the
 * CSR, account email update, certificate revocation).
 *
 * @package Sentora
 * @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Updated by RusTus, 2026-11-08
 */
namespace Analogic\ACME;

use \RuntimeException;
use \Psr\Log\LoggerInterface;

/**
 * ACME v2 client: registers the account, proves domain control with http-01 and fetches certificates.
 */
class Lescript
{
    /** @var string ACME server base URL (production). */
    public $ca = 'https://acme-v02.api.letsencrypt.org'; // PRODUCTION ONLY
    //public $ca = 'https://acme-staging-v02.api.letsencrypt.org'; // TESTING ONLY!!!!!
    /** @var string Country code (C) for the CSR. */
    public $countryCode;
    /** @var string State or province (ST) for the CSR. */
    public $state;
    /** @var string The challenge type to use (only http-01 is supported). */
    public $challenge = 'http-01'; // http-01 challange only
    /** @var string[] Account contact URIs, e.g. 'mailto:me@example.com'. */
    public $contact = array(); // optional

	/** @var string User-Agent sent to the ACME server. */
	public $clientUserAgent = "analogic-lescript/0.3.0";
	
    /** @var string Folder where the keys, CSR and certificates are written. */
    protected $certificatesDir;
    /** @var string Web root of the domain; the challenge token goes in .well-known/acme-challenge. */
    protected $webRootDir;

    /** @var LoggerInterface */
    protected $logger;
    /** @var ClientInterface */
    protected $client;
    /** @var string Path of the account's private key (private.pem). */
    protected $accountKeyPath;

    /** @var string The account URL (kid), '' until known. */
    protected $accountId = '';
    /** @var string The newAccount URL from the ACME directory. */
    protected $urlNewAccount = '';
    /** @var string The newNonce URL from the ACME directory. */
    protected $urlNewNonce = '';
    /** @var string The newOrder URL from the ACME directory. */
    protected $urlNewOrder = '';
	
	// NEW CODE - tg - Added counntry code and state
    /**
     * Set up the client with its folders, logger and CSR details.
     *
     * @param string $accountDir Folder of the account; the key is read from <accountDir>_account/private.pem.
     * @param string $certificatesDir Folder where the domain key, CSR and certificates are written.
     * @param string $webRootDir Web root of the domain (for the http-01 token).
     * @param object|null $logger Anything with an info() method (the module's Logger); null echoes the messages.
     * @param string $countryCode Country code (C) for the CSR.
     * @param string $state State or province (ST) for the CSR.
     * @param ClientInterface|null $client Optional. The HTTP client; null makes a Client for $ca. Default null.
     */
    public function __construct($accountDir, $certificatesDir, $webRootDir, $logger, $countryCode, $state, ?ClientInterface $client = null)
    {
		$this->accountDir = $accountDir;
        $this->certificatesDir = $certificatesDir;
        $this->webRootDir = $webRootDir;
        $this->logger = $logger;
		$this->countryCode = $countryCode;
		$this->state = $state;
        $this->client = $client ? $client : new Client($this->ca, $this->clientUserAgent);
        $this->accountKeyPath = $accountDir . '_account/private.pem';
    }

    /**
     * Load the ACME directory and register the account (or look up its ID when the key exists).
     *
     * @return void
     * @throws RuntimeException When no account ID is returned.
     */
    public function initAccount()
    {
        $this->initCommunication();

        if (!is_file($this->accountKeyPath)) {

            // generate and save new private key for account
            // ---------------------------------------------
            $this->log('Starting new account registration');
            $this->generateKey(dirname($this->accountKeyPath));
            $this->postNewReg();
            $this->log('New account certificate registered');
			
        } else {
            $this->log('Account already registered. Continuing.');
            $this->getAccountId();
        }

        if (empty($this->accountId)) {
            throw new RuntimeException("We don't have account ID");
        }

        $this->log("Account: " . $this->accountId);
    }

    /**
     * Read the ACME directory URLs and get a first nonce.
     *
     * @return void
     * @throws RuntimeException When the directory lacks a needed URL.
     */
    public function initCommunication()
    {
		$this->log('ACME Client: '.$this->clientUserAgent);
        $this->log('Getting list of URLs for API');

        $directory = $this->client->get('/directory');
        if (!isset($directory['newNonce']) || !isset($directory['newAccount']) || !isset($directory['newOrder']) || !isset($directory['revokeCert']) ) {
            throw new RuntimeException("Missing setup urls");
        }

        $this->urlNewNonce = $directory['newNonce'];
        $this->urlNewAccount = $directory['newAccount'];
        $this->urlNewOrder = $directory['newOrder'];
		$this->urlRevokeCert = $directory['revokeCert'];

        $this->log('Requesting new nonce for client communication');
        $this->client->get($this->urlNewNonce);
    }

    /**
     * Order, validate (http-01) and download a certificate for the domains.
     *
     * Writes private.pem, last.csr, fullchain.pem, cert.pem and chain.pem to the certificates folder.
     *
     * @param string[] $domains The domain names; the first is the certificate's CN.
     * @param bool $reuseCsr Optional. Reuse last.csr when it exists. Default false.
     * @return void
     * @throws RuntimeException On any ACME or file error.
     */
    public function signDomains(array $domains, $reuseCsr = false)
    {
        $this->log('Starting certificate generation process for domains');

        $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        // start domains authentication
        // ----------------------------
        $this->log("Requesting challenge for ".join(', ', $domains));
        $response = $this->signedRequest(
            $this->urlNewOrder,
            array("identifiers" => array_map(
                function ($domain) { 
					return array("type" => "dns", "value" => $domain);
				}, 
                $domains
                ))
        );

        $finalizeUrl = $response['finalize'];

        foreach ($response['authorizations'] as $authz) {
            // 1. getting authentication requirements
            // --------------------------------------
            $response = $this->signedRequest($authz, "");
            $domain = $response['identifier']['value'];
            // (Let's Encrypt reuses a name it checked recently: nothing to do for it)
            if (isset($response['status']) && $response['status'] === 'valid') {
                $this->log("$domain was checked recently, no new check needed");
                continue;
            }
            if (empty($response['challenges'])) {
                throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));
            }

            $self = $this;
            $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self) {
                return $v ? $v : ($w['type'] == $self->challenge ? $w : false);
            });
            if (!$challenge) throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));

            $this->log("Got challenge token for $domain");

            // 2. saving authentication token for web verification
            // ---------------------------------------------------
            $directory = $this->webRootDir . '/.well-known/acme-challenge';
            $tokenPath = $directory . '/' . $challenge['token'];

            if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                throw new RuntimeException("Couldn't create directory to expose challenge: {$tokenPath}");
            }

            $header = array(
                // need to be in precise order!
                "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

            );
            $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

            file_put_contents($tokenPath, $payload);
            chmod($tokenPath, 0644);

            // 3. verification process itself
            // -------------------------------
            $uri = "http://{$domain}/.well-known/acme-challenge/{$challenge['token']}";

            $this->log("Token for $domain saved at $tokenPath and should be available at $uri");

            $this->log("Sending request to challenge");
                
            // ask for the check once, then ask how it went (sending the answer again while Let's Encrypt
            // checks is refused with "authorization must be pending", which hid the real result)
            $result = $this->signedRequest($challenge['url'], array("keyAuthorization" => $payload));
            $status = isset($result['status']) ? $result['status'] : '';
            $authzNow = null;
            for ($try = 1; $try <= 15 && in_array($status, array('', 'pending', 'processing'), true); $try++) {
                sleep($try < 5 ? 2 : 4);
                $authzNow = $this->signedRequest($authz, "");
                $status = isset($authzNow['status']) ? $authzNow['status'] : '';
                $this->log("Check of $domain: " . ($status !== '' ? $status : 'no answer'));
            }
            if ($status !== 'valid') {
                // Let's Encrypt's own reason, from the challenge it tried
                $why = '';
                foreach (array_merge(array($result), isset($authzNow['challenges']) ? $authzNow['challenges'] : array()) as $c) {
                    if (!empty($c['error']['detail'])) {
                        $why = $c['error']['detail'];
                    }
                }
                @unlink($tokenPath);
                throw new RuntimeException($domain . ': ' . ($why !== '' ? $why
                    : ($status === 'pending' || $status === 'processing' ? 'the check did not finish in time, try again in a few minutes' : 'the check ended with status ' . ($status !== '' ? $status : 'unknown'))));
            }
            $result = array('status' => $status);

            $this->log("Verification ended with status: {$result['status']}");

            @unlink($tokenPath);
        } 

        // requesting certificate
        // ----------------------
        $domainPath = $this->getDomainPath(reset($domains));

        // generate private key for domain if not exist
        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
            $this->generateKey($domainPath);
        }

        // load domain key
        $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

        $this->client->getLastLinks();

        $csr = $reuseCsr && is_file($domainPath . "/last.csr") ?
            $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, $domains);

        $finalizeResponse = $this->signedRequest($finalizeUrl, array('csr' => $csr));

        if ($this->client->getLastCode() > 299 || $this->client->getLastCode() < 200) {
            throw new RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($finalizeResponse));
        }
        
        $maxAllowedLoops = 6;
        $loopCount = 1;
		
		$lastLocationUrl = $this->client->getLastLocation();
		
		
        while ($loopCount < $maxAllowedLoops) {
            $this->log("Firing Order Status Request Nr. " . $loopCount . " to: " . $lastLocationUrl);
            $OrderStatusResponse = $this->signedRequest($lastLocationUrl, "");

            if (($this->client->getLastCode() > 299 || $this->client->getLastCode() < 200)) {
                throw new RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($OrderStatusResponse));
            }

            if (isset($OrderStatusResponse['status']) && $OrderStatusResponse['status'] === "invalid") {
                throw new RuntimeException("The order failed: " . (isset($OrderStatusResponse['error']['detail']) ? $OrderStatusResponse['error']['detail'] : json_encode($OrderStatusResponse)));
            }
            if (($OrderStatusResponse['status'] == "valid" && !empty($OrderStatusResponse['certificate']))) {
                $this->log("Order Status: " . $OrderStatusResponse['status']);
                $location = $OrderStatusResponse['certificate'];
                break;
            }

            $sleepTime = $loopCount * $loopCount; // 1 4 9 16 25 36
            $loopCount++;

            $this->log("Order Status not 'valid' yet but '" . $OrderStatusResponse['status'] . "', sleeping " . $sleepTime . "s");
            sleep($sleepTime);
        }

        if (empty($location)) {
            throw new RuntimeException("Certificate generation processing timed out (Status not 'valid')");
        }

        // waiting loop
        $certificates = array();
        for ($download = 1; ; $download++) {
            if ($download > 15) {
                throw new RuntimeException("The certificate was not ready to download in time: try again in a few minutes");
            }
            $this->client->getLastLinks();

            $result = $this->signedRequest($location, "");

            if ($this->client->getLastCode() == 202) {

                $this->log("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else if ($this->client->getLastCode() == 200) {

                $this->log("Got certificate! YAY!");
                $serverCert = $this->parseFirstPemFromBody($result);
                $certificates[] = $serverCert;
                $certificates[] = substr($result, strlen($serverCert)); // rest of ca certs

                break;
            } else {

                throw new RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());

            }
        }

        if (empty($certificates)) throw new RuntimeException('No certificates generated');

        $this->log("Saving fullchain.pem");
        file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

        $this->log("Saving cert.pem");
        file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

        $this->log("Saving chain.pem");
        file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

        $this->log("Done !!§§!");
    }

    /**
     * Load a private key from a PEM file.
     *
     * @param string $path The path of the PEM file.
     * @return \OpenSSLAsymmetricKey
     * @throws RuntimeException When the key cannot be read.
     */
    protected function readPrivateKey($path)
    {
        if (($key = openssl_pkey_get_private('file://' . $path)) === FALSE) {
            throw new RuntimeException(openssl_error_string());
        }

        return $key;
    }

    /**
     * Get the first certificate from a PEM chain.
     *
     * @param string $body The PEM chain.
     * @return string The first certificate block.
     */
    protected function parseFirstPemFromBody($body)
    {
        preg_match('~(-----BEGIN.*?END CERTIFICATE-----)~s', $body, $matches);

        return $matches[1];
    }

    /**
     * Get the folder for a domain's files (always the certificates folder in Sentora).
     *
     * @param string $domain The domain name (unused).
     * @return string
     */
    protected function getDomainPath($domain)
    {
        //tg return $this->certificatesDir . '/' . $domain . '/';
		return $this->certificatesDir;
    }

    /**
     * Look up the account ID by registering again with the existing key.
     *
     * @return array<string, mixed>|string The ACME response.
     */
    protected function getAccountId()
    {
        return $this->postNewReg();
    }

    /**
     * Send the newAccount request and keep the account URL from the Location header.
     *
     * @return array<string, mixed>|string The ACME response.
     */
    protected function postNewReg()
    {
        $data = array(
            'termsOfServiceAgreed' => true
        );

        $this->log('Sending registration to letsencrypt server');

        if ($this->contact) {
            $data['contact'] = $this->contact;
        }

        $response = $this->signedRequest(
            $this->urlNewAccount,
            $data
        );
        $lastLocation = $this->client->getLastLocation();
        if (!empty($lastLocation)) {
            $this->accountId = $lastLocation;
        }
        return $response;
    }

    /**
     * Make a CSR for the domains (all of them as subjectAltName) and save it as last.csr.
     *
     * @param \OpenSSLAsymmetricKey $privateKey The domain's private key.
     * @param string[] $domains The domain names; the first is the CN.
     * @return string The CSR in base64url DER form.
     * @throws RuntimeException When the CSR cannot be made.
     */
    protected function generateCSR($privateKey, array $domains)
    {
        $domain = reset($domains);
        $san = implode(",", array_map(function ($dns) {
            return "DNS:" . $dns;
        }, $domains));
        $tmpConf = tmpfile();
        $tmpConfMeta = stream_get_meta_data($tmpConf);
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
subjectAltName = ' . $san . '
keyUsage = nonRepudiation, digitalSignature, keyEncipherment');

        // (country and region only when known: an empty or wrong one makes openssl refuse the request)
        $dn = array("CN" => $domain);
        if (trim((string) $this->state) !== '') {
            $dn["ST"] = (string) $this->state;
        }
        if (preg_match('/^[A-Z]{2}$/', (string) $this->countryCode)) {
            $dn["C"] = (string) $this->countryCode;
        }
        $dn["O"] = "Unknown";
        $csr = openssl_csr_new(
            $dn,
            $privateKey,
            array(
                "config" => $tmpConfPath,
                "digest_alg" => "sha256"
            )
        );

        if (!$csr) throw new RuntimeException("CSR couldn't be generated! " . openssl_error_string());

        openssl_csr_export($csr, $csr);
        fclose($tmpConf);

        $csrPath = $this->getDomainPath($domain) . "/last.csr";
        file_put_contents($csrPath, $csr);

        return $this->getCsrContent($csrPath);
    }

    /**
     * Read a PEM CSR file and convert it to base64url DER for the finalize request.
     *
     * @param string $csrPath The path of the CSR file.
     * @return string
     */
    protected function getCsrContent($csrPath) {
        $csr = file_get_contents($csrPath);

        preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

        return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
    }

    /**
     * Make a 4096-bit RSA key pair and save it as private.pem and public.pem.
     *
     * @param string $outputDirectory The folder to write to (made when missing).
     * @return void
     * @throws RuntimeException When the key or folder cannot be made.
     */
    protected function generateKey($outputDirectory)
    {
        $res = openssl_pkey_new(array(
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "private_key_bits" => 4096,
        ));

        if (!openssl_pkey_export($res, $privateKey)) {
            throw new RuntimeException("Key export failed!");
        }

        $details = openssl_pkey_get_details($res);

        if (!is_dir($outputDirectory)) @mkdir($outputDirectory, 0700, true);
        if (!is_dir($outputDirectory)) throw new RuntimeException("Cant't create directory $outputDirectory");

        file_put_contents($outputDirectory . '/private.pem', $privateKey);
        file_put_contents($outputDirectory . '/public.pem', $details['key']);
    }

    /**
     * Send a JWS-signed POST to the ACME server (kid when the account is known, else jwk).
     *
     * @param string $uri The URL.
     * @param array<string,mixed>|string $payload The payload; '' for a POST-as-GET.
     * @param string|null $nonce Optional. The nonce; null uses the last one. Default null.
     * @return array<string, mixed>|string The parsed JSON response, or the raw body.
     */
    protected function signedRequest($uri, $payload, $nonce = null)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $this->accountId;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" : str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64 . '.' . $payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending signed request to $uri");

        try {
            return $this->client->post($uri, json_encode($data));
        } catch (RuntimeException $e) {
            // Let's Encrypt sometimes refuses a nonce; its answer carries a fresh one to try once more with
            if ($nonce === null && strpos($e->getMessage(), 'badNonce') !== false) {
                $this->log("Nonce refused, trying once more with a fresh one");
                return $this->signedRequest($uri, $payload, $this->client->getLastNonce());
            }
            throw $e;
        }
    }

    /**
     * Write a message to the logger, or echo it when there is none.
     *
     * @param string $message The message.
     * @return void
     */
    protected function log($message)
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            echo $message . "\n";
        }
    }
	
	//################################################################## 
	
	
	/**
	 * Update the account's contact addresses to $contact.
	 *
	 * @return array<string, mixed>|string The ACME response.
	 */
	public function postUpdateRegEmail()
    {

        $data = array(
			'contact' => $this->contact
		);

        $this->log('Requesting to update Email on account...');

        $response = $this->updateRequest(
			$this->accountId,
            $data
        );
        $lastLocation = $this->client->getLastLocation();
        if (!empty($lastLocation)) {
            $this->accountId = $lastLocation;
        }
        return $response;
    }

    /**
     * Send a signed account update request (kid is the account URL).
     *
     * @param string $uri The account URL.
     * @param array<string,mixed>|string $payload The payload.
     * @param string|null $nonce Optional. The nonce; null uses the last one. Default null.
     * @return array<string, mixed>|string The parsed JSON response, or the raw body.
     */
    public function updateRequest($uri, $payload, $nonce = null)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $uri;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" : str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

		//FOR TESTING
		//$sendDATA = array(
			//"URL" => $uri,
			//"payload" => $payload,
			//"Protected" =>	$protected
		//);

		$this->log("Sending request to update account email...");
		// TESTING ONLY
		//echo print_r($sendDATA);
		
        return $this->client->post($uri, json_encode($data));
		
		//$this->log("Request accepted. Email Updated.");
		
    }
	
	
	/**
	 * Revoke a certificate.
	 *
	 * @param string $certData The certificate in base64url DER form.
	 * @return array<string, mixed>|string The ACME response.
	 */

    // --- Sentora: helpers shared by the panel (controller) and the daily renewal (OnDaemonDay) --------

    /**
     * Does the name have an address at all (A record, also through a CNAME)? Whether it reaches this
     * server is Let's Encrypt's to find out, and it says so when not (see explain()).
     *
     * @param string $name
     * @return bool
     */
    public static function hasAddress($name)
    {
        return checkdnsrr($name, 'A') || checkdnsrr($name, 'CNAME');
    }

    /**
     * Does the name have an address in public DNS? Asked of a public resolver over HTTPS, because this
     * server may use an internal DNS that answers differently (split DNS).
     *
     * @param string $name
     * @return bool|null True or false, null when the public resolver could not be asked.
     */
    public static function hasPublicAddress($name)
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $h = curl_init('https://cloudflare-dns.com/dns-query?name=' . rawurlencode($name) . '&type=A');
        curl_setopt_array($h, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => array('Accept: application/dns-json')));
        $j = json_decode((string) curl_exec($h), true);
        if (!is_array($j) || !isset($j['Status'])) {
            return null;
        }
        // Status 0 = NOERROR; an A answer (type 1), also at the end of a CNAME chain
        foreach (isset($j['Answer']) ? $j['Answer'] : array() as $a) {
            if (isset($a['type']) && (int) $a['type'] === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * The names a certificate for the domain covers: a subdomain only itself; a main domain also its
     * www. name, unless public DNS has no address for www. (one name Let's Encrypt can not check
     * fails the whole request).
     *
     * @param string $domain
     * @param bool $isSubdomain
     * @param string|null $note Set to a sentence when www. was left out.
     * @return string[]
     */
    public static function namesFor($domain, $isSubdomain, &$note = null)
    {
        $note = null;
        if ($isSubdomain) {
            return array($domain);
        }
        if (self::hasPublicAddress('www.' . $domain) === false) {
            $note = "www.$domain is not in public DNS, so the certificate covers $domain only (add a DNS record for www and request it again to include it).";
            return array($domain);
        }
        return array($domain, 'www.' . $domain);
    }

    /**
     * The country and region for the certificate request, from the server's public address: the
     * panel's IP when it is a public one, else the address ip-api.com sees this server come from
     * (a local panel IP is refused there). Let's Encrypt does not put them in the certificate.
     *
     * @return array{0: string, 1: string} Two-letter country code (or '') and region (or '').
     */
    public static function serverLocation()
    {
        $ip = trim((string) \ctrl_options::GetSystemOption('server_ip'));
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : '';
        $ctx = stream_context_create(array('http' => array('timeout' => 8)));
        $a = json_decode((string) @file_get_contents('http://ip-api.com/json/' . $public, false, $ctx));
        if (!$a || !isset($a->status) || $a->status !== 'success') {
            return array('', '');
        }
        return array(preg_match('/^[A-Z]{2}$/', (string) $a->countryCode) ? (string) $a->countryCode : '', (string) $a->regionName);
    }

    /**
     * Is the certificate due for renewal? When a third of its own lifetime is left, as Let's
     * Encrypt advises: 30 days for today's 90-day certificates, and still right for the 64-day
     * (February 2027) and 45-day (February 2028) ones. A missing or unreadable one is due.
     *
     * @param string $certFile cert.pem
     * @return bool
     */
    public static function renewalDue($certFile)
    {
        $c = is_file($certFile) ? openssl_x509_parse((string) file_get_contents($certFile)) : false;
        if (!$c || empty($c['validTo_time_t']) || empty($c['validFrom_time_t'])) {
            return true;
        }
        $life = max(86400, (int) $c['validTo_time_t'] - (int) $c['validFrom_time_t']);
        return time() > (int) $c['validTo_time_t'] - (int) ($life / 3);
    }

    /**
     * What went wrong, in plain words with what to do, followed by Let's Encrypt's own message.
     *
     * @param string $message The exception message (Let's Encrypt's problem document, its detail, or ours).
     * @return string
     */
    public static function explain($message)
    {
        $m = (string) $message;
        $type = preg_match('/urn:ietf:params:acme:error:([A-Za-z]+)/', $m, $t) ? $t[1] : '';
        $detail = preg_match('/"detail"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $m, $d) ? stripcslashes($d[1]) : $m;
        $detail = trim(preg_replace('/\s+/', ' ', preg_replace('/^\d{3}\s/', '', $detail)));
        // the name it is about: "... for www.example.com", else the URL it fetched, else our "example.com:" prefix
        $name = preg_match('/\bfor "?([a-z0-9.-]+\.[a-z]{2,})/i', $detail, $n) || preg_match('/https?:\/\/([a-z0-9.-]+\.[a-z]{2,})/i', $detail, $n)
            || preg_match('/^([a-z0-9.-]+\.[a-z]{2,}):/i', $detail, $n) ? $n[1] : 'the domain';
        $rules = array(
            // [pattern on type or detail, what it means, what to do]
            array('/rateLimited|too many (certificates|failed|new orders|requests)/i', "Let's Encrypt's limit for this domain or account is reached (too many certificates or failed attempts recently).", 'Wait until the time it names, then try once. Meanwhile, fix the cause of earlier failures; every failed try counts.'),
            array('/caa|CAA record/i', "The domain's DNS has a CAA record that does not allow Let's Encrypt.", 'At your DNS host, add a CAA record: 0 issue "letsencrypt.org" (or remove the CAA records), then try again.'),
            array('/rejectedIdentifier|Policy forbids|not a valid public|invalid domain|Domain name does not end with a valid public suffix|\.local\b/i', "Let's Encrypt does not issue certificates for this name (an internal name such as .local, or not a public domain).", 'Only names in public DNS can get one. For an internal name, use a self-signed certificate.'),
            array('/NXDOMAIN|no valid A records|no valid AAAA records|DNS problem|SERVFAIL|looking up (A|AAAA|CAA)/i', "$name has no working address in public DNS.", "At your DNS host, add an A record for $name pointing to this server's public address (behind a router: the router's public IP), wait for it to take effect, then try again."),
            array('/Timeout during connect|Connection refused|connection reset|Timeout after connect|network is unreachable|firewall/i', "Let's Encrypt could not reach this server on port 80 (http) at $name.", 'Open port 80 to this server: forward it on the router (behind one) and allow it in any firewall. It must stay open: renewals use it too.'),
            array('/Invalid response from.*(404|403|401|500)|unauthorized|Incorrect validation|The key authorization file/i', "Let's Encrypt reached a web server at $name, but not this site's check file.", 'Check that the DNS points to this server and not to an old one, and that the site does not block /.well-known/acme-challenge/ (an .htaccess rule, a redirect to another host).'),
            array('/redirect/i', "A redirect at $name sent Let's Encrypt somewhere it can not follow.", 'Redirects may only go to http or https on ports 80 or 443. Fix or remove the redirect for /.well-known/acme-challenge/.'),
            array('/did not finish in time|timed out|not ready to download/i', "Let's Encrypt took longer than usual.", 'Try again in a few minutes.'),
            array('/badNonce|serverInternal|503|502|500 Internal|Service (Temporarily )?Unavailable/i', "Let's Encrypt had a temporary problem.", 'Try again later.'),
            array('/Curl:|Could not resolve host|Failed to connect to acme|SSL certificate problem/i', "This server could not reach Let's Encrypt.", 'Check that the server can reach https://acme-v02.api.letsencrypt.org (internet access, DNS, outgoing firewall).'),
        );
        foreach ($rules as $r) {
            if (preg_match($r[0], $type . ' ' . $detail)) {
                return $r[1] . ' What to do: ' . $r[2] . " (Let's Encrypt said: " . substr($detail, 0, 300) . ')';
            }
        }
        return "Let's Encrypt said: " . substr($detail, 0, 400);
    }

	function postRevoke($certData) {
		
		$reason = "1";
		
        $data = array(
            'certificate' => $certData
			//'reason' => $reason
        );

        $this->log('Sending request to revoke certificate');

        $response = $this->revokeRequest(
            $this->urlRevokeCert,
            $data
        );
        // Sentora: a refusal (already revoked, not this account's, bad request) was taken as success
        if ((int) $this->client->getLastCode() !== 200) {
            throw new RuntimeException("Revoke refused: HTTP code " . $this->client->getLastCode() . ", " . json_encode($response));
        }
        //$lastLocation = $this->client->getLastLocation();
        //if (!empty($lastLocation)) {
           // $this->accountId = $lastLocation;
        //}
        return $response;
    }
	
	
	/**
	 * Send a signed revokeCert request.
	 *
	 * @param string $uri The revokeCert URL.
	 * @param array<string,mixed>|string $payload The payload.
	 * @param string|null $nonce Optional. The nonce; null uses the last one. Default null.
	 * @return array<string, mixed>|string The parsed JSON response, or the raw body.
	 */
	function revokeRequest($uri, $payload, $nonce = null) {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $this->accountId;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" :  json_encode($payload));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending revoke cert request to $uri");

        return $this->client->post($uri, json_encode($data));
    }
		
}

/**
 * HTTP client used by Lescript to talk to the ACME server.
 */
interface ClientInterface
{
    /**
     * Constructor
     *
     * @param string $base the ACME API base all relative requests are sent to
	 * @param string $userAgent ACME Client User-Agent
     */
    public function __construct($base, $userAgent);
    /**
     * Send a POST request
     *
     * @param string $url URL to post to
     * @param string $data the JSON body to send (Lescript posts a JSON-encoded JWS)
     * @return array<string, mixed>|string the parsed JSON response, raw response on error
     */
    public function post($url, $data);
    /**
     * Send a GET request
     *
     * @param string $url URL to request via get
     * @return array<string, mixed>|string the parsed JSON response, raw response on error
     */
    public function get($url);
    /**
     * Returns the Replay-Nonce header of the last request
     *
     * if no request has been made, yet. A GET on $base/directory is done and the
     * resulting nonce returned
     *
     * @return string
     */
    public function getLastNonce();
    /**
     * Return the Location header of the last request
     *
     * returns null if last request had no location header
     *
     * @return string|null
     */
    public function getLastLocation();
    /**
     * Return the HTTP status code of the last request
     *
     * @return int|null
     */
    public function getLastCode();
    /**
     * Get all Link headers of the last request
     *
     * @return string[]
     */
    public function getLastLinks();
}

/**
 * cURL implementation of ClientInterface.
 */
class Client implements ClientInterface
{
    /** @var int|null HTTP status code of the last request. */
    protected $lastCode;
    /** @var string|null Response headers of the last request. */
    protected $lastHeader;

    /** @var string ACME API base URL for relative requests. */
    protected $base;
	/** @var string User-Agent header value. */
	protected $userAgent;

    /**
     * Set up the client.
     *
     * @param string $base The ACME API base all relative requests are sent to.
     * @param string $userAgent The User-Agent header value.
     */
    public function __construct($base, $userAgent)
    {
        $this->base = $base;
		$this->userAgent = $userAgent;
    }

    /**
     * Send a request with cURL and keep its headers and status code.
     *
     * @param string $method 'GET' or 'POST'.
     * @param string $url An absolute URL, or a path relative to $base.
     * @param string|null $data Optional. The POST body. Default null.
     * @return array<string, mixed>|string The parsed JSON response, or the raw body.
     * @throws RuntimeException On a cURL error or an HTTP 4xx/5xx answer.
     */
    protected function curl($method, $url, $data = null)
    {
        $headers = array('Accept: application/json', 'Content-Type: application/jose+json');
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, preg_match('~^http~', $url) ? $url : $this->base . $url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, true);
		curl_setopt($handle, CURLOPT_USERAGENT, $this->userAgent);

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
        }
        $response = curl_exec($handle);

        if (curl_errno($handle)) {
            throw new RuntimeException('Curl: ' . curl_error($handle));
        }

        $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        $this->lastHeader = $header;
        $this->lastCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if ($this->lastCode >= 400 && $this->lastCode < 600) {
            throw new RuntimeException($this->lastCode . "\n".$body);
        }

        $data = json_decode($body, true);
        return $data === null ? $body : $data;
    }

    /**
     * Send a POST request.
     *
     * @param string $url The URL.
     * @param string $data The JSON body.
     * @return array<string, mixed>|string The parsed JSON response, or the raw body.
     */
    public function post($url, $data)
    {
        return $this->curl('POST', $url, $data);
    }

    /**
     * Send a GET request.
     *
     * @param string $url The URL.
     * @return array<string, mixed>|string The parsed JSON response, or the raw body.
     */
    public function get($url)
    {
        return $this->curl('GET', $url);
    }

    /**
     * Get the Replay-Nonce header of the last request.
     *
     * @return string
     * @throws RuntimeException When there is none.
     */
    public function getLastNonce()
    {
        if (preg_match('~Replay-Nonce: (.+)~i', $this->lastHeader, $matches)) {
            return trim($matches[1]);
        }
        
        throw new RuntimeException("We don't have nonce");
    }

    /**
     * Get the Location header of the last request.
     *
     * @return string|null Null when there was none.
     */
    public function getLastLocation()
    {
        if (preg_match('~^Location: (.+)$~im', $this->lastHeader, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Get the HTTP status code of the last request.
     *
     * @return int|null
     */
    public function getLastCode()
    {
        return $this->lastCode;
    }

    /**
     * Get the rel="up" Link headers of the last request.
     *
     * @return string[]
     */
    public function getLastLinks()
    {
        preg_match_all('~Link: <(.+)>;rel="up"~', $this->lastHeader, $matches);
        return $matches[1];
    }
}

/**
 * Base64url encoding without padding (RFC 4648 section 5), as JWS uses.
 */
class Base64UrlSafeEncoder
{
    /**
     * Encode to base64url without padding.
     *
     * @param string $input The raw bytes.
     * @return string
     */
    public static function encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * Decode base64url (padding optional).
     *
     * @param string $input The base64url text.
     * @return string|false The raw bytes, or false on invalid input.
     */
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
