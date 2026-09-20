<?php

/**
 * Small EFS Card Management SOAP client.
 *
 * EFS requires a custom login token for every authenticated method. Card
 * updates deliberately use getCard -> mutate -> setCard so fields controlled
 * in the EFS portal are not accidentally cleared.
 */
final class EfsCardService
{
    private const PROD_WSDL = 'https://ws.efsllc.com/axis2/services/CardManagementWS?wsdl';
    private const QA_WSDL = 'https://ws.partner.efsllc.com/axis2/services/CardManagementWS?wsdl';

    private string $username;
    private string $password;
    private string $wsdl;
    private ?SoapClient $client = null;
    private ?string $clientId = null;

    public function __construct(string $username, string $password, ?string $wsdl = null)
    {
        $this->username = trim($username);
        $this->password = $password;
        $this->wsdl = trim((string)$wsdl) ?: self::PROD_WSDL;

        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('EFS web-service credentials are not configured.');
        }
        if (!class_exists('SoapClient')) {
            throw new RuntimeException('The PHP SOAP extension is not enabled on this server.');
        }
    }

    public static function fromEnvironment(): self
    {
        $suffix = (defined('LONESTAR_IS_UAT') && LONESTAR_IS_UAT) ? 'UAT' : 'PROD';
        $username = self::envFirst(['EFS_WS_USERNAME_' . $suffix, 'EFS_WS_USERNAME']);
        $password = self::envFirst(['EFS_WS_PASSWORD_' . $suffix, 'EFS_WS_PASSWORD']);
        $defaultWsdl = $suffix === 'UAT' ? self::QA_WSDL : self::PROD_WSDL;
        $wsdl = self::envFirst(['EFS_WS_WSDL_' . $suffix, 'EFS_WS_WSDL']) ?: $defaultWsdl;
        return new self($username, $password, $wsdl);
    }

    public static function isConfigured(): bool
    {
        $suffix = (defined('LONESTAR_IS_UAT') && LONESTAR_IS_UAT) ? 'UAT' : 'PROD';
        return self::envFirst(['EFS_WS_USERNAME_' . $suffix, 'EFS_WS_USERNAME']) !== ''
            && self::envFirst(['EFS_WS_PASSWORD_' . $suffix, 'EFS_WS_PASSWORD']) !== '';
    }

    public function getCard(string $cardNumber): object
    {
        $cardNumber = $this->validateCardNumber($cardNumber);
        $response = $this->callAuthenticated('getCard', [$cardNumber]);
        $card = $this->unwrap($response, 'result');
        if (!is_object($card) || !isset($card->header)) {
            throw new RuntimeException('EFS returned an incomplete card record.');
        }
        return $card;
    }

    public function getStatus(string $cardNumber): string
    {
        $card = $this->getCard($cardNumber);
        return trim((string)($card->header->status ?? ''));
    }

    public function setActive(string $cardNumber, bool $active): string
    {
        $cardNumber = $this->validateCardNumber($cardNumber);
        $card = $this->getCard($cardNumber);
        $wanted = $active ? 'Active' : 'Inactive';
        $card->header->status = $wanted;
        $this->callAuthenticated('setCard', [$card]);

        $actual = $this->getStatus($cardNumber);
        if (strcasecmp($actual, $wanted) !== 0) {
            throw new RuntimeException("EFS did not confirm the requested {$wanted} status (reported: " . ($actual ?: 'unknown') . ').');
        }
        return $actual;
    }

    public function close(): void
    {
        if ($this->client && $this->clientId) {
            try {
                $this->client->__soapCall('logout', [$this->clientId]);
            } catch (Throwable $ignored) {
                // The session expires server-side; logout failure must not mask
                // the result of the card operation.
            }
        }
        $this->clientId = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function callAuthenticated(string $method, array $arguments)
    {
        $this->login();
        try {
            return $this->client->__soapCall($method, array_merge([$this->clientId], $arguments));
        } catch (SoapFault $fault) {
            if (stripos($fault->getMessage(), 'InvalidClientId') !== false) {
                $this->clientId = null;
                $this->login();
                return $this->client->__soapCall($method, array_merge([$this->clientId], $arguments));
            }
            throw $fault;
        }
    }

    private function login(): void
    {
        if ($this->clientId !== null) {
            return;
        }
        if ($this->client === null) {
            $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $ssl['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            $this->client = new SoapClient($this->wsdl, [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_BOTH,
                'connection_timeout' => 20,
                'keep_alive' => true,
                'stream_context' => stream_context_create(['ssl' => $ssl]),
                'user_agent' => 'LoneStar-FuelCardManager/1.0',
            ]);
        }
        $response = $this->client->__soapCall('login', [$this->username, $this->password]);
        $token = trim((string)$this->unwrap($response, 'result'));
        if ($token === '') {
            throw new RuntimeException('EFS login did not return a client ID.');
        }
        $this->clientId = $token;
    }

    private function validateCardNumber(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\s+/', '', trim($cardNumber));
        if ($cardNumber === '' || !preg_match('/^[0-9]{10,25}$/', $cardNumber)) {
            throw new InvalidArgumentException('A full 10-25 digit EFS card number is required in External ID.');
        }
        return $cardNumber;
    }

    private function unwrap($value, string $property)
    {
        return is_object($value) && property_exists($value, $property) ? $value->{$property} : $value;
    }

    private static function envFirst(array $names): string
    {
        foreach ($names as $name) {
            // Apache may rename per-directory SetEnv variables after an
            // internal redirect (for example, .php -> extensionless URL).
            // PHP hosting configurations also differ on whether those values
            // appear in getenv(), $_SERVER, or both.
            foreach ([$name, 'REDIRECT_' . $name] as $candidate) {
                $envValue = getenv($candidate);
                if ($envValue !== false && (string)$envValue !== '') {
                    return (string)$envValue;
                }

                if (isset($_SERVER[$candidate]) && (string)$_SERVER[$candidate] !== '') {
                    return (string)$_SERVER[$candidate];
                }
            }
        }
        return '';
    }
}
