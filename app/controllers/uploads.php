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

        if ($image && file_exists($image->value)) {
            $this->Download->streamFile($image->value);
            exit;
        }
        $this->redirect('404');
    }

    /**
     * Handle theme logos
     */
    public function themes()
    {
        if (!isset($this->get[0]) && !isset($this->get[1])) {
            $this->redirect('404');
        }

        $type = strtolower($this->get[0]);
        $asset = strtolower($this->get[1]);
        $uploads_dir = $this->SettingsCollection->fetchSetting($this->Companies, $this->company_id, 'uploads_dir');
        $upload_path = $uploads_dir['value'] . $this->company_id . DS . 'themes' . DS;

        if ($type == 'asset' && $asset && file_exists($upload_path . $asset)) {
            $mime_type = 'application/octet-stream';
            if (function_exists('mime_content_type')) {
                $mime_type = mime_content_type($upload_path . $asset);
            } else {
                $asset_parts = explode('.', $asset);
                if (strtolower(array_pop($asset_parts)) == 'svg') {
                    $mime_type = 'image/svg+xml';
                }
            }

            // Add security headers for image assets to prevent XSS in directly-accessed SVGs
            if (in_array($mime_type, ['image/svg+xml', 'image/gif', 'image/jpeg', 'image/png'])) {
                header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none';");
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: DENY');
            }

            $this->Download->setContentType($mime_type);
            $this->Download->streamFile($upload_path . $asset);
            exit;
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
            $gravatar_url = 'https://www.gravatar.com/avatar/'
                . hash('sha256', strtolower(trim($contact->email))) . '?d=mp&s=512';

            header('Content-type: image/jpeg');
            echo file_get_contents($gravatar_url);
        }

        exit;
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

        $this->Download->streamFile($default_avatar);

        exit;
    }
}
