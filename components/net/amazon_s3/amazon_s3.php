<?php
/**
 * Amazon S3 component that backs up file data.
 *
 * @package blesta
 * @subpackage components.net.amazon_s3
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AmazonS3 extends S3
{
    /**
     * @var string The S3 region
     */
    private $region = null;
    /**
     * @var array Key/value pairs of regions to endpoint mappings
     */
    private static $locations = [
        'us-east-1' => 's3.us-east-1.amazonaws.com',
        'us-east-2' => 's3.us-east-2.amazonaws.com',
        'us-west-2' => 's3.us-west-2.amazonaws.com',
        'us-west-1' => 's3.us-west-1.amazonaws.com',
        'af-south-1' => 's3.af-south-1.amazonaws.com',
        'eu-central-1' => 's3.eu-central-1.amazonaws.com',
        'eu-central-2' => 's3.eu-central-2.amazonaws.com',
        'eu-west-1' => 's3.eu-west-1.amazonaws.com',
        'eu-west-2' => 's3.eu-west-2.amazonaws.com',
        'eu-west-3' => 's3.eu-west-3.amazonaws.com',
        'eu-north-1' => 's3.eu-north-1.amazonaws.com',
        'eu-south-1' => 's3.eu-south-1.amazonaws.com',
        'eu-south-2' => 's3.eu-south-2.amazonaws.com',
        'ap-east-1' => 's3.ap-east-1.amazonaws.com',
        'ap-south-1' => 's3.ap-south-1.amazonaws.com',
        'ap-south-2' => 's3.ap-south-2.amazonaws.com',
        'ap-southeast-1' => 's3.ap-southeast-1.amazonaws.com',
        'ap-southeast-2' => 's3.ap-southeast-2.amazonaws.com',
        'ap-southeast-3' => 's3.ap-southeast-3.amazonaws.com',
        'ap-southeast-4' => 's3.ap-southeast-4.amazonaws.com',
        'ap-southeast-5' => 's3.ap-southeast-5.amazonaws.com',
        'ap-southeast-7' => 's3.ap-southeast-7.amazonaws.com',
        'ap-northeast-1' => 's3.ap-northeast-1.amazonaws.com',
        'ap-northeast-2' => 's3.ap-northeast-2.amazonaws.com',
        'ap-northeast-3' => 's3.ap-northeast-3.amazonaws.com',
        'ca-central-1' => 's3.ca-central-1.amazonaws.com',
        'ca-west-1' => 's3.ca-west-1.amazonaws.com',
        'sa-east-1' => 's3.sa-east-1.amazonaws.com',
        'il-central-1' => 's3.il-central-1.amazonaws.com',
        'mx-central-1' => 's3.mx-central-1.amazonaws.com',
        'me-south-1' => 's3.me-south-1.amazonaws.com',
        'me-central-1' => 's3.me-central-1.amazonaws.com',
    ];
    /**
     * @var array Key/value pairs of regions to region name mappings
     */
    private static $regions = [
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-2' => 'US West (Oregon) Region',
        'us-west-1' => 'US West (Northern California) Region',
        'af-south-1' => 'Africa (Cape Town)',
        'eu-central-1' => 'Europe (Frankfurt)',
        'eu-central-2' => 'Europe (Zurich)',
        'eu-west-1' => 'Europe (Ireland)',
        'eu-west-2' => 'Europe (London)',
        'eu-west-3' => 'Europe (Paris)',
        'eu-north-1' => 'Europe (Stockholm)',
        'eu-south-1' => 'Europe (Milan)',
        'eu-south-2' => 'Europe (Spain)',
        'ap-east-1' => 'Asia Pacific (Hong Kong)',
        'ap-south-1' => 'Asia Pacific (Mumbai)',
        'ap-south-2' => 'Asia Pacific (Hyderabad)',
        'ap-southeast-1' => 'Asia Pacific (Singapore) Region',
        'ap-southeast-2' => 'Asia Pacific (Sydney) Region',
        'ap-southeast-3' => 'Asia Pacific (Jakarta)',
        'ap-southeast-4' => 'Asia Pacific (Melbourne)',
        'ap-southeast-5' => 'Asia Pacific (Malaysia)',
        'ap-southeast-7' => 'Asia Pacific (Thailand)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo) Region',
        'ap-northeast-2' => 'Asia Pacific (Seoul)',
        'ap-northeast-3' => 'Asia Pacific (Osaka)',
        'ca-central-1' => 'Canada (Central)',
        'ca-west-1' => 'Canada West (Calgary)',
        'sa-east-1' => 'South America (Sao Paulo) Region',
        'il-central-1' => 'Israel (Tel Aviv)',
        'mx-central-1' => 'Mexico (Central)',
        'me-south-1' => 'Middle East (Bahrain)',
        'me-central-1' => 'Middle East (UAE)',
    ];

    /**
     * Constructs a new AmazonS3 component, setting the credentials
     *
     * @param string $access_key The access key
     * @param string $secret_key The secret key
     * @param bool $use_ssl Whether or not to use SSL when communicating
     * @param string $region The S3 region name
     */
    public function __construct($access_key, $secret_key, $use_ssl = true, $region = null)
    {
        $this->region = $region;
        $endpoint = isset(self::$locations[$region]) ? self::$locations[$region] : self::$locations['us-east-1'];

        parent::__construct($access_key, $secret_key, $use_ssl, $endpoint);
    }

    /**
     * Returns the region currently set
     *
     * @return string The S3 region name
     */
    public function getRegion()
    {
        return $this->region;
    }

    /**
     * Returns an array of key/value pair region to region name mappings
     *
     * @return array A key/value pair of region to region name mappings
     */
    public static function getRegions()
    {
        return self::$regions;
    }

    /**
     * Uploads a file to Amazon S3
     *
     * @param string $file The full path of the file on the local system to upload
     * @param string $bucket The name of the bucket to upload to
     * @param string $remote_file_name The name of the file on the S3 server, null will default to the
     *  same file name as the local file
     * @return bool True if the file was successfully uploaded, false otherwise
     */
    public function upload($file, $bucket, $remote_file_name = null)
    {
        if (!file_exists($file)) {
            return false;
        }

        if ($remote_file_name === null) {
            $remote_file_name = baseName($file);
        }

        if ($this->putObjectFile($file, $bucket, $remote_file_name)) {
            return true;
        }
        return false;
    }
}
