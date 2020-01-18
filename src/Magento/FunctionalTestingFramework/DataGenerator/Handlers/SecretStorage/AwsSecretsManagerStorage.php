<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\DataGenerator\Handlers\SecretStorage;

use Magento\FunctionalTestingFramework\Config\MftfApplicationConfig;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;
use Magento\FunctionalTestingFramework\Util\Logger\LoggingUtil;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Aws\Result;
use InvalidArgumentException;
use Exception;

class AwsSecretsManagerStorage extends BaseStorage
{
    /**
     * Mftf project path
     */
    const MFTF_PATH = 'mftf';

    /**
     * AWS Secrets Manager version
     *
     * Last tested version '2017-10-17'
     */
    const LATEST_VERSION = 'latest';

    /**
     * SecretsManagerClient client
     *
     * @var SecretsManagerClient
     */
    private $client = null;

    /**
     * AwsSecretsManagerStorage constructor
     *
     * @param string $region
     * @param string $profile
     * @throws TestFrameworkException
     * @throws InvalidArgumentException
     */
    public function __construct($region, $profile = null)
    {
        parent::__construct();
        $this->createAwsSecretsManagerClient($region, $profile);
    }

    /**
     * Returns the value of a secret based on corresponding key
     *
     * @param string $key
     * @return string|null
     * @throws Exception
     */
    public function getEncryptedValue($key)
    {
        // Check if secret is in cached array
        if (null !== ($value = parent::getEncryptedValue($key))) {
            return $value;
        }

        if (MftfApplicationConfig::getConfig()->verboseEnabled()) {
            LoggingUtil::getInstance()->getLogger(AwsSecretsManagerStorage::class)->debug(
                "Retrieving value for key name {$key} from AWS Secrets Manager"
            );
        }

        $reValue = null;
        try {
            // Split vendor/key to construct secret id
            list($vendor, $key) = explode('/', trim($key, '/'), 2);
            $secretId = self::MFTF_PATH
                . '/'
                . $vendor
                . '/'
                . $key;
            // Read value by id from AWS Secrets Manager, and parse the result
            $value = $this->parseAwsSecretResult(
                $this->client->getSecretValue(['SecretId' => $secretId]),
                $key
            );
            // Encrypt value for return
            $reValue = openssl_encrypt($value, parent::ENCRYPTION_ALGO, parent::$encodedKey, 0, parent::$iv);
            parent::$cachedSecretData[$key] = $reValue;
        } catch (AwsException $e) {
            $error = $e->getAwsErrorCode();
            if (MftfApplicationConfig::getConfig()->verboseEnabled()) {
                LoggingUtil::getInstance()->getLogger(AwsSecretsManagerStorage::class)->debug(
                    "AWS error code: {$error}. Unable to read value for key {$key} from AWS Secrets Manager"
                );
            }
        } catch (\Exception $e) {
            if (MftfApplicationConfig::getConfig()->verboseEnabled()) {
                LoggingUtil::getInstance()->getLogger(AwsSecretsManagerStorage::class)->debug(
                    "Unable to read value for key {$key} from AWS Secrets Manager"
                );
            }
        }
        return $reValue;
    }

    /**
     * Parse AWS result object and return secret for key
     *
     * @param Result $awsResult
     * @param string $key
     * @return string
     * @throws TestFrameworkException
     */
    private function parseAwsSecretResult($awsResult, $key)
    {
        // Return secret from the associated KMS CMK
        if (isset($awsResult['SecretString'])) {
            $rawSecret = $awsResult['SecretString'];
        } else {
            throw new TestFrameworkException("Error parsing result from AWS Secrets Manager");
        }
        $secret = json_decode($rawSecret, true);
        if (isset($secret[$key])) {
            return $secret[$key];
        }
        throw new TestFrameworkException("Error parsing result from AWS Secrets Manager");
    }

    /**
     * Create Aws Secrets Manager client
     *
     * @param string $region
     * @param string $profile
     * @return void
     * @throws TestFrameworkException
     * @throws InvalidArgumentException
     */
    private function createAwsSecretsManagerClient($region, $profile)
    {
        if (null !== $this->client) {
            return;
        }

        // Create AWS Secrets Manager client
        $this->client = new SecretsManagerClient([
            'profile' => $profile,
            'region' => $region,
            'version' => self::LATEST_VERSION
        ]);

        if ($this->client === null) {
            throw new TestFrameworkException("Unable to create AWS Secrets Manager client");
        }
    }
}