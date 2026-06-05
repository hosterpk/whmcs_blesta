<?php

/**
 * Allows access to files uploaded to the uploads directory, which likely resides
 * above a publically accessible directory
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Uploads extends AppController
{
    /**
     * Pre-action setup method that is called before the index method, or the set controller action
     */
    public function preAction()
    {
        parent::preAction();

        $this->components(['Download']);
        $this->uses(['Companies', 'Users', 'Clients', 'Staff']);
    }

    /**
     * Can not access this resource
     */
    public function index()
    {
        $this->redirect('404');
    }

    /**
     * Handle invoice logos and backgrounds
     */
    public function invoices()
    {
        if (!isset($this->get[0])) {
            $this->redirect('404');
        }

        $type = strtolower($this->get[0]);

        switch ($type) {
            case 'inv_logo':
            case 'inv_background':
                break;
            default:
                $this->redirect('404');
        }

        $image = $this->Companies->getSetting($this->company_id, $type);

        $this->streamImage($image->value);
    }

    /**
     * Handle theme logos
     */
    public function themes()
    {
        if (!isset($this->get[0]) || !isset($this->get[1])) {
            $this->redirect('404');
        }

        $type = strtolower($this->get[0]);
        $asset = basename(strtolower($this->get[1]));
        $uploads_dir = $this->SettingsCollection->fetchSetting($this->Companies, $this->company_id, 'uploads_dir');
        $upload_path = $uploads_dir['value'] . $this->company_id . DS . 'themes' . DS;

        if ($type == 'asset' && $asset && file_exists($upload_path . $asset)) {
            $this->streamImage($upload_path . $asset);
        }

        $this->redirect('404');
    }

    /**
     * Handle package option value images
     */
    public function options()
    {
        $asset = $this->get[0] ?? null;

        if (!$asset) {
            $this->redirect('404');
        }

        // Sanitize: only allow a bare filename (no directory traversal)
        $asset = basename($asset);

        $uploads_dir = $this->SettingsCollection->fetchSetting(
            $this->Companies,
            $this->company_id,
            'uploads_dir'
        );
        $upload_path = $uploads_dir['value'] . $this->company_id . DS . 'package_options' . DS;

        if ($asset && file_exists($upload_path . $asset)) {
            $this->streamImage($upload_path . $asset);
        }

        $this->redirect('404');
    }

    /**
     * Handle user avatars
     */
    public function avatar()
    {
        $user_id = (int) ($this->get[0] ?? null);
        $user = $this->Users->get($user_id);

        if (!$user || empty($this->get[0])) {
            $this->getDefaultAvatar();
        }

        // Fetch the avatar mode from the Support Manager settings
        $avatar = 'default';
        if (
            $this->PluginManager->isInstalled('support_manager', $this->company_id)
            && ($this->get['support_manager'] ?? 'false') == 'true'
        ) {
            $this->uses(['SupportManager.SupportManagerSettings']);

            $avatar = $this->SupportManagerSettings->getSetting('avatar', $this->company_id);
            $avatar = $avatar->value ?? 'default';
        }

        if ($avatar == 'default' || $avatar == 'fallback') {
            if ($user->avatar && file_exists($user->avatar)) {
                $this->Download->streamFile($user->avatar);
            } else {
                if ($avatar == 'fallback') {
                    $avatar = 'gravatar';
                } else {
                    $this->getDefaultAvatar();
                }
            }
        }

        // Gravatar
        if ($avatar == 'gravatar') {
            $contact = $this->Clients->getByUserId($user_id);
            if (!$contact) {
                $contact = $this->Staff->getByUserId($user_id);
            }

            if (!$contact) {
                $this->getDefaultAvatar();
            }

            $gravatar_url = 'https://www.gravatar.com/avatar/'
                . hash('sha256', strtolower(trim($contact->email))) . '?d=mp&s=512';

            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none';");
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Content-type: image/jpeg');

            $ch = curl_init($gravatar_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response !== false) {
                echo $response;
            }
        }

        exit;
    }

    /**
     * Maps a whitelisted image file extension to its canonical response Content-Type.
     * Using the validated extension rather than mime_content_type() prevents libmagic
     * misdetection (e.g. an SVG containing inline script being reported as text/html)
     * from steering the browser into HTML parsing of the response.
     *
     * @param string $file_ext The lowercase file extension including the leading dot
     * @return string The Content-Type to send, or application/octet-stream if unrecognized
     */
    private function mimeTypeForImageExtension($file_ext)
    {
        $map = [
            '.gif' => 'image/gif',
            '.jpg' => 'image/jpeg',
            '.jpeg' => 'image/jpeg',
            '.png' => 'image/png',
            '.svg' => 'image/svg+xml',
            '.webp' => 'image/webp',
        ];

        return $map[$file_ext] ?? 'application/octet-stream';
    }

    /**
     * Streams the default avatar image
     */
    private function getDefaultAvatar()
    {
        $this->uses(['PluginManager']);

        $this->Download->addAllowedPath(VIEWDIR . 'default' . DS . 'images');

        if (
            $this->PluginManager->isInstalled('support_manager', $this->company_id)
            && ($this->get['support_manager'] ?? 'false') == 'true'
        ) {
            $this->uses(['SupportManager.SupportManagerSettings']);

            $default_avatar = $this->SupportManagerSettings->getSetting('default_avatar', $this->company_id);
            $default_avatar = !empty($default_avatar->value)
                ? $default_avatar->value
                : (VIEWDIR . 'default' . DS . 'images' . DS . 'avatar.jpg');
        } else {
            $default_avatar = VIEWDIR . 'default' . DS . 'images' . DS . 'avatar.jpg';
        }

        // Validate the avatar path resides within one of the expected directories:
        // the stock images directory, or the company's uploaded avatars directory.
        // The realpath comparison prevents traversal outside these bases via a
        // crafted default_avatar setting value.
        $uploads_dir = $this->SettingsCollection->fetchSetting($this->Companies, $this->company_id, 'uploads_dir');
        $allowed_dirs = [
            realpath(VIEWDIR . 'default' . DS . 'images'),
            realpath(($uploads_dir['value'] ?? '') . $this->company_id . DS . 'avatars')
        ];
        $avatar_realpath = realpath($default_avatar);

        $is_allowed = false;
        if ($avatar_realpath !== false) {
            foreach ($allowed_dirs as $allowed_dir) {
                if ($allowed_dir !== false
                    && str_starts_with($avatar_realpath . DIRECTORY_SEPARATOR, $allowed_dir . DIRECTORY_SEPARATOR)
                ) {
                    $is_allowed = true;
                    break;
                }
            }
        }

        if (!$is_allowed) {
            $default_avatar = VIEWDIR . 'default' . DS . 'images' . DS . 'avatar.jpg';
        }

        $this->streamImage($default_avatar);
    }

    /**
     * Streams an image file with security validation
     *
     * @param string $image_path The file path to the image
     */
    private function streamImage($image_path)
    {
        if (!$image_path || !file_exists($image_path)) {
            $this->redirect('404');
            exit;
        }

        // Validate file extension (skip for extensionless files, MIME check below will validate)
        Configure::load('mime');
        $allowed_extensions = Configure::get('Blesta.allowed_file_extensions');
        $allowed_extensions = $allowed_extensions['image'] ?? [];
        $extension = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));

        if ($extension !== '' && !in_array('.' . $extension, $allowed_extensions)) {
            $this->redirect('404');
            exit;
        }

        // Validate mime type
        $mime_type = null;
        if (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($image_path);
        } elseif ($extension !== '') {
            // Fall back to extension-based mime detection when the fileinfo extension is unavailable.
            // Safe because the extension was already validated against the allow-list above.
            $mime_type = $this->mimeTypeForImageExtension('.' . $extension);
        }

        $allowed_mimes = Configure::get('Blesta.allowed_mime_types');
        $allowed_mimes = $allowed_mimes['image'] ?? [];
        if (!$mime_type || !in_array($mime_type, $allowed_mimes)) {
            $this->redirect('404');
            exit;
        }

        // Security headers for image assets
        if (in_array($mime_type, $allowed_mimes)) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none';");
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
        }

        $this->Download->setContentType($mime_type);
        $this->Download->streamFile($image_path);
        exit;
    }
}
