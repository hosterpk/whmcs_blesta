<?php
use Blesta\Core\Util\Components\Component;
use Blesta\Core\Util\Components\Type\File;

/**
 * Download component
 *
 * Simplifies the file download/stream process by setting the appropriate headers
 *
 * @package blesta
 * @subpackage components.download
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Download extends Component
{
    use File;

    /**
     * @var string The encoding used to deliver the file
     */
    private $encoding;

    /**
     * @var array An array of content types to set when deliverying the file
     */
    private $content_type;

    /**
     * Initializes the Download object, sets the default encoding
     *
     * @param string $encoding The download encoding (optional, default binary)
     */
    public function __construct($encoding = 'binary')
    {
        $this->setEncoding($encoding);
        $this->setContentType('application/octet-stream');

        // Fetch uploads directory from the system settings
        $SettingsCollection = new SettingsCollection();
        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        $uploads_dir = $SettingsCollection->fetchSetting(
            $this->Companies,
            Configure::get('Blesta.company_id'),
            'uploads_dir'
        );
        $temp_dir = $SettingsCollection->fetchSetting(
            $this->Companies,
            Configure::get('Blesta.company_id'),
            'temp_dir'
        );

        $this->addAllowedPath(Configure::get('Blesta.upload_tmp_dir'));
        if (isset($uploads_dir['value'])) {
            $this->addAllowedPath(realpath($uploads_dir['value']));
        }
        if (isset($temp_dir['value'])) {
            $this->addAllowedPath(realpath($temp_dir['value']));
        }
    }

    /**
     * Overrides the currently set file encoding
     *
     * @param string $encoding The file encoding to set for all subsequent streams or downloads
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    /**
     * Overrides the currently set Content Type for this file
     *
     * @param mixed $content_type A numerically indexed array of content types
     *  to set, or a string containing the content type to set
     */
    public function setContentType($content_type)
    {
        if (is_array($content_type)) {
            $this->content_type = $content_type;
        } else {
            $this->content_type = [$content_type];
        }
    }

    /**
     * Streams a file out to the standard output stream (e.g. a browser)
     *
     * @param string $file The full path to the file name to be streamed
     * @param string $file_name The name to give the file being streamed (defaults to the real file name)
     * @param string $send_mode The send mode for the file (file to send
     *  normally, xsendfile to send through mod_xsendfile)
     */
    public function streamFile($file, $file_name = null, $send_mode = 'file')
    {
        if ($file_name == null) {
            $file_name = basename($file);
        }

        if (($file = realpath($file)) === false || !$this->isPathAllowed($file)) {
            return false;
        }

        $file_name = $this->sanitizeFileName($file_name);

        $this->setHeader('Content-Description', 'File Transfer');
        foreach ($this->content_type as $type) {
            $this->setHeader('Content-Type', $type);
        }
        $this->setHeader('Content-Disposition', 'inline; filename="' . $file_name . '"');
        $this->setHeader('Expires', '0');
        $this->setHeader('Cache-Control', 'public, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'public');
        $this->setHeader('Content-Length', filesize($file));

        $this->output($file, $send_mode);
    }

    /**
     * Streams the given data to the standard output stream (e.g. a browser)
     *
     * @param string $file_name The name of the file to be streamed
     * @param string $data The data to be streamed
     */
    public function streamData($file_name, $data)
    {
        $file_name = $this->sanitizeFileName($file_name);

        $this->setHeader('Content-Description', 'File Transfer');
        foreach ($this->content_type as $type) {
            $this->setHeader('Content-Type', $type);
        }
        $this->setHeader('Content-Disposition', 'inline; filename="' . $file_name . '"');
        $this->setHeader('Expires', '0');
        $this->setHeader('Cache-Control', 'public, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'public');
        $this->setHeader('Content-Length', strlen($data));

        $this->output($data, 'data');
    }

    /**
     * Forces download of a file
     *
     * @param string $file The full path to the file name to be downloaded
     * @param string $file_name The name to give the file being downloaded (defaults to the real file name)
     * @param string $send_mode The send mode for the file (file to send
     *  normally, xsendfile to send through mod_xsendfile)
     */
    public function downloadFile($file, $file_name = null, $send_mode = 'file')
    {
        if ($file_name == null) {
            $file_name = basename($file);
        }

        if (($file = realpath($file)) === false || !$this->isPathAllowed($file)) {
            return false;
        }

        $file_name = $this->sanitizeFileName($file_name);

        $this->setHeader('Content-Description', 'File Transfer');
        foreach ($this->content_type as $type) {
            $this->setHeader('Content-Type', $type);
        }
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $file_name . '"');
        $this->setHeader('Content-Transfer-Encoding', $this->encoding);
        $this->setHeader('Expires', '0');
        $this->setHeader('Cache-Control', 'public, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'public');
        $this->setHeader('Content-Length', filesize($file));

        $this->output($file, $send_mode);
    }

    /**
     * Forces download of the given data
     *
     * @param string $file_name The name of the file to be downloaded
     * @param string $data The data to be downloaded
     */
    public function downloadData($file_name, $data)
    {
        $file_name = $this->sanitizeFileName($file_name);

        $this->setHeader('Content-Description', 'File Transfer');
        foreach ($this->content_type as $type) {
            $this->setHeader('Content-Type', $type);
        }
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $file_name . '"');
        $this->setHeader('Content-Transfer-Encoding', $this->encoding);
        $this->setHeader('Expires', '0');
        $this->setHeader('Cache-Control', 'public, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'public');
        $this->setHeader('Content-Length', strlen($data));

        $this->output($data, 'data');
    }

    /**
     * Checks whether the given resolved file path is within any of the allowed paths
     *
     * @param string $file The realpath-resolved file path to check
     * @return bool True if the file is within an allowed path, false otherwise
     */
    private function isPathAllowed($file)
    {
        if (str_contains($file, '..')) {
            return false;
        }

        foreach ($this->allowed_paths as $path) {
            if ($path
                && (str_starts_with(
                    $file . DIRECTORY_SEPARATOR,
                    rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                ) || $file === $path)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitizes a file name for safe use in HTTP headers by stripping CR/LF and escaping quotes
     *
     * @param string $file_name The file name to sanitize
     * @return string The sanitized file name
     */
    private function sanitizeFileName($file_name)
    {
        $file_name = str_replace(["\r", "\n"], '', $file_name);
        $file_name = str_replace('"', '\\"', $file_name);

        return $file_name;
    }

    /**
     * Sets a header
     *
     * @param string $field The name of the header to set
     * @param string $value The value of the header to set
     */
    private function setHeader($field, $value)
    {
        header($field . ': ' . $value);
    }

    /**
     * Sends the file to the output stream and ends the request
     *
     * @param string $file The file path or data to output
     * @param string $type The type of $file (data, file, or xsendfile)
     */
    private function output($file, $type)
    {
        switch ($type) {
            // output file as data
            case 'data':
                echo $file;
                break;
            // output file as filename
            case 'file':
                readfile($file);
                break;
            // mod_xsendfile as filename
            case 'xsendfile':
                $this->setHeader('X-Sendfile', $file);
                break;
        }
    }
}
