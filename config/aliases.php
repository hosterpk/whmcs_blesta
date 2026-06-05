<?php
/**
 * Class Aliases Configuration
 *
 * This configuration handles the aliasing of namespaced classes to global classes.
 * It serves as a compatibility layer, allowing legacy code, extensions, and customizations
 * to continue functioning by accessing new namespaced classes via their original
 * global (root-level) class names.
 *
 * Examples:
 * - Use 'Input' instead of 'Minphp\Input\Input'
 * - Use 'ModuleFields' instead of 'Blesta\Core\Util\Input\Fields\InputFields'
 *
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

return [
    'Record' => Blesta\Core\Database\Record::class,
    'ModuleFields' => Blesta\Core\Util\Input\Fields\InputFields::class,
    'ModuleField' => Blesta\Core\Util\Input\Fields\InputField::class,

    'AppModel' => Blesta\App\AppModel::class,

    'Accounts' => Blesta\App\Models\Accounts::class,
    'Actions' => Blesta\App\Models\Actions::class,
    'AiConversations' => Blesta\App\Models\AiConversations::class,
    'AiMessages' => Blesta\App\Models\AiMessages::class,
    'ApiKeys' => Blesta\App\Models\ApiKeys::class,
    'Backup' => Blesta\App\Models\Backup::class,
    'Blacklist' => Blesta\App\Models\Blacklist::class,
    'CalendarEvents' => Blesta\App\Models\CalendarEvents::class,
    'ClientGroups' => Blesta\App\Models\ClientGroups::class,
    'Clients' => Blesta\App\Models\Clients::class,
    'Companies' => Blesta\App\Models\Companies::class,
    'Contacts' => Blesta\App\Models\Contacts::class,
    'Countries' => Blesta\App\Models\Countries::class,
    'CouponPackageOptions' => Blesta\App\Models\CouponPackageOptions::class,
    'CouponTerms' => Blesta\App\Models\CouponTerms::class,
    'Coupons' => Blesta\App\Models\Coupons::class,
    'CronTasks' => Blesta\App\Models\CronTasks::class,
    'Currencies' => Blesta\App\Models\Currencies::class,
    'DataFeeds' => Blesta\App\Models\DataFeeds::class,
    'ElectronicInvoices' => Blesta\App\Models\ElectronicInvoices::class,
    'EmailGroups' => Blesta\App\Models\EmailGroups::class,
    'EmailHtmlTemplates' => Blesta\App\Models\EmailHtmlTemplates::class,
    'EmailVerifications' => Blesta\App\Models\EmailVerifications::class,
    'Emails' => Blesta\App\Models\Emails::class,
    'EmailSnapshots' => Blesta\App\Models\EmailSnapshots::class,
    'Encryption' => Blesta\App\Models\Encryption::class,
    'GatewayManager' => Blesta\App\Models\GatewayManager::class,
    'InvoiceTemplateManager' => Blesta\App\Models\InvoiceTemplateManager::class,
    'Invoices' => Blesta\App\Models\Invoices::class,
    'Languages' => Blesta\App\Models\Languages::class,
    'License' => Blesta\App\Models\License::class,
    'LicenseManager' => Blesta\App\Models\LicenseManager::class,
    'Logs' => Blesta\App\Models\Logs::class,
    'ManagedAccounts' => Blesta\App\Models\ManagedAccounts::class,
    'Marketplace' => Blesta\App\Models\Marketplace::class,
    'MessageGroups' => Blesta\App\Models\MessageGroups::class,
    'Messages' => Blesta\App\Models\Messages::class,
    'MessengerManager' => Blesta\App\Models\MessengerManager::class,
    'ModuleClientMeta' => Blesta\App\Models\ModuleClientMeta::class,
    'ModuleManager' => Blesta\App\Models\ModuleManager::class,
    'ModuleTypes' => Blesta\App\Models\ModuleTypes::class,
    'Navigation' => Blesta\App\Models\Navigation::class,
    'Notifications' => Blesta\App\Models\Notifications::class,
    'PackageGroups' => Blesta\App\Models\PackageGroups::class,
    'PackageOptionConditionSets' => Blesta\App\Models\PackageOptionConditionSets::class,
    'PackageOptionConditions' => Blesta\App\Models\PackageOptionConditions::class,
    'PackageOptionGroups' => Blesta\App\Models\PackageOptionGroups::class,
    'PackageOptions' => Blesta\App\Models\PackageOptions::class,
    'Packages' => Blesta\App\Models\Packages::class,
    'PasswordResets' => Blesta\App\Models\PasswordResets::class,
    'Payments' => Blesta\App\Models\Payments::class,
    'Permissions' => Blesta\App\Models\Permissions::class,
    'PluginManager' => Blesta\App\Models\PluginManager::class,
    'Pricings' => Blesta\App\Models\Pricings::class,
    'Quotations' => Blesta\App\Models\Quotations::class,
    'ReportManager' => Blesta\App\Models\ReportManager::class,
    'ServiceChanges' => Blesta\App\Models\ServiceChanges::class,
    'ServiceInvoices' => Blesta\App\Models\ServiceInvoices::class,
    'Services' => Blesta\App\Models\Services::class,
    'Settings' => Blesta\App\Models\Settings::class,
    'Staff' => Blesta\App\Models\Staff::class,
    'StaffGroups' => Blesta\App\Models\StaffGroups::class,
    'States' => Blesta\App\Models\States::class,
    'SystemEvents' => Blesta\App\Models\SystemEvents::class,
    'SystemUpgrade' => Blesta\App\Models\SystemUpgrade::class,
    'TaxProviders' => Blesta\App\Models\TaxProviders::class,
    'Taxes' => Blesta\App\Models\Taxes::class,
    'Themes' => Blesta\App\Models\Themes::class,
    'Transactions' => Blesta\App\Models\Transactions::class,
    'Users' => Blesta\App\Models\Users::class,

    'Color' => Blesta\Helpers\Color\Color::class,
    'Css' => Blesta\Helpers\Css\Css::class,
    'CurrencyFormat' => Blesta\Helpers\CurrencyFormat\CurrencyFormat::class,
    'DataStructure' => Blesta\Helpers\DataStructure\DataStructure::class,
    'DataStructureString' => Blesta\Helpers\DataStructure\String\DataStructureString::class,
    'DataStructureArray' => Blesta\Helpers\DataStructure\Array\DataStructureArray::class,
    'SettingsProcessor' => Blesta\Helpers\SettingsProcessor\SettingsProcessor::class,
    'TextParser' => Blesta\Helpers\TextParser\TextParser::class,
    'Widget' => Blesta\Helpers\Widget\Widget::class,
    'WidgetClient' => Blesta\Helpers\WidgetClient\WidgetClient::class,

    'VCard' => Blesta\Components\VCard\VCard::class,
    'Upload' => Blesta\Components\Upload\Upload::class,
    'SettingsCollection' => Blesta\Components\SettingsCollection\SettingsCollection::class,
    'SessionCart' => Blesta\Components\SessionCart\SessionCart::class,
    'Security' => Blesta\Components\Security\Security::class,
    'Download' => Blesta\Components\Download\Download::class,
    'Email' => Blesta\Components\Email\Email::class,
    'BlestaAi' => Blesta\Components\BlestaAi\BlestaAi::class,
];
