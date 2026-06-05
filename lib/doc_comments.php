<?php

use Blesta\App\Models\Accounts;
use Blesta\App\Models\Actions;
use Blesta\App\Models\ApiKeys;
use Blesta\App\Models\Backup;
use Blesta\App\Models\Blacklist;
use Blesta\App\Models\CalendarEvents;
use Blesta\App\Models\ClientGroups;
use Blesta\App\Models\Clients;
use Blesta\App\Models\Companies;
use Blesta\App\Models\Contacts;
use Blesta\App\Models\Countries;
use Blesta\App\Models\CouponPackageOptions;
use Blesta\App\Models\CouponTerms;
use Blesta\App\Models\Coupons;
use Blesta\App\Models\CronTasks;
use Blesta\App\Models\Currencies;
use Blesta\App\Models\DataFeeds;
use Blesta\App\Models\ElectronicInvoices;
use Blesta\App\Models\EmailGroups;
use Blesta\App\Models\EmailHtmlTemplates;
use Blesta\App\Models\EmailVerifications;
use Blesta\App\Models\Emails;
use Blesta\App\Models\Encryption;
use Blesta\App\Models\GatewayManager;
use Blesta\App\Models\InvoiceTemplateManager;
use Blesta\App\Models\Invoices;
use Blesta\App\Models\Languages;
use Blesta\App\Models\License;
use Blesta\App\Models\LicenseManager;
use Blesta\App\Models\Logs;
use Blesta\App\Models\ManagedAccounts;
use Blesta\App\Models\Marketplace;
use Blesta\App\Models\MessageGroups;
use Blesta\App\Models\Messages;
use Blesta\App\Models\MessengerManager;
use Blesta\App\Models\ModuleClientMeta;
use Blesta\App\Models\ModuleManager;
use Blesta\App\Models\ModuleTypes;
use Blesta\App\Models\Navigation;
use Blesta\App\Models\Notifications;
use Blesta\App\Models\PackageGroups;
use Blesta\App\Models\PackageOptionConditionSets;
use Blesta\App\Models\PackageOptionConditions;
use Blesta\App\Models\PackageOptionGroups;
use Blesta\App\Models\PackageOptions;
use Blesta\App\Models\Packages;
use Blesta\App\Models\PasswordResets;
use Blesta\App\Models\Payments;
use Blesta\App\Models\Permissions;
use Blesta\App\Models\PluginManager;
use Blesta\App\Models\Pricings;
use Blesta\App\Models\Quotations;
use Blesta\App\Models\ReportManager;
use Blesta\App\Models\ServiceChanges;
use Blesta\App\Models\ServiceInvoices;
use Blesta\App\Models\Services;
use Blesta\App\Models\Settings;
use Blesta\App\Models\Staff;
use Blesta\App\Models\StaffGroups;
use Blesta\App\Models\States;
use Blesta\App\Models\SystemEvents;
use Blesta\App\Models\TaxProviders;
use Blesta\App\Models\Taxes;
use Blesta\App\Models\Themes;
use Blesta\App\Models\Transactions;
use Blesta\App\Models\Users;

use Blesta\Helpers\Color\Color;
use Blesta\Helpers\CurrencyFormat\CurrencyFormat;
use Blesta\Helpers\DataStructure\DataStructure;
use Blesta\Helpers\Css\Css;
use Blesta\Helpers\Widget\Widget;
use Blesta\Helpers\WidgetClient\WidgetClient;

if (false) {
    /**
     * Models
     * @property Accounts $Accounts
     * @property Actions $Actions
     * @property ApiKeys $ApiKeys
     * @property Backup $Backup
     * @property CalendarEvents $CalendarEvents
     * @property ClientGroups $ClientGroups
     * @property Clients $Clients
     * @property Companies $Companies
     * @property Contacts $Contacts
     * @property Countries $Countries
     * @property CouponPackageOptions $CouponPackageOptions
     * @property CouponTerms $CouponTerms
     * @property Coupons $Coupons
     * @property CronTasks $CronTasks
     * @property Currencies $Currencies
     * @property DataFeeds $DataFeeds
     * @property EmailGroups $EmailGroups
     * @property EmailVerifications $EmailVerifications
     * @property Emails $Emails
     * @property Encryption $Encryption
     * @property GatewayManager $GatewayManager
     * @property InvoiceTemplateManager $InvoiceTemplateManager
     * @property ElectronicInvoices $ElectronicInvoices
     * @property Invoices $Invoices
     * @property Languages $Languages
     * @property License $License
     * @property LicenseManager $LicenseManager
     * @property Logs $Logs
     * @property Marketplace $Marketplace
     * @property MessageGroups $MessageGroups
     * @property Messages $Messages
     * @property MessengerManager $MessengerManager
     * @property ModuleClientMeta $ModuleClientMeta
     * @property ModuleManager $ModuleManager
     * @property ModuleTypes $ModuleTypes
     * @property Navigation $Navigation
     * @property Notifications $Notifications
     * @property PackageGroups $PackageGroups
     * @property PackageOptionConditionSets $PackageOptionConditionSets
     * @property PackageOptionConditions $PackageOptionConditions
     * @property PackageOptionGroups $PackageOptionGroups
     * @property PackageOptions $PackageOptions
     * @property Packages $Packages
     * @property Payments $Payments
     * @property Permissions $Permissions
     * @property PluginManager $PluginManager
     * @property Pricings $Pricings
     * @property ReportManager $ReportManager
     * @property ServiceChanges $ServiceChanges
     * @property ServiceInvoices $ServiceInvoices
     * @property Services $Services
     * @property Settings $Settings
     * @property Staff $Staff
     * @property StaffGroups $StaffGroups
     * @property States $States
     * @property SystemEvents $SystemEvents
     * @property TaxProviders $TaxProviders
     * @property Taxes $Taxes
     * @property Themes $Themes
     * @property Transactions $Transactions
     * @property Users $Users
     * @property Quotations $Quotations
     * @property ManagedAccounts $ManagedAccounts
     * @property Blacklist $Blacklist
     * @property PasswordResets $PasswordResets
     * @property EmailHtmlTemplates $EmailHtmlTemplates
     *
     * Components
     * @property Download $Email
     * @property Email $Email
     * @property Input $Input
     * @property InvoiceDelivery $InvoiceDelivery
     * @property QuotationDelivery $QuotationDelivery
     * @property InvoiceFormats $InvoiceFormats
     * @property InvoiceTemplates $InvoiceTemplates
     * @property SettingsCollection $SettingsCollection
     * @property Upload $Upload
     * @property Record $Record
     * @property Session $Session
     * @property Upgrades $Upgrades
     * @property Net $Net
     * @property Security $Security
     *
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Css $Css
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class Controller { /* Do Nothing */ }

    /**
     * Models
     * @property Accounts $Accounts
     * @property Actions $Actions
     * @property ApiKeys $ApiKeys
     * @property Backup $Backup
     * @property CalendarEvents $CalendarEvents
     * @property ClientGroups $ClientGroups
     * @property Clients $Clients
     * @property Companies $Companies
     * @property Contacts $Contacts
     * @property Countries $Countries
     * @property CouponPackageOptions $CouponPackageOptions
     * @property CouponTerms $CouponTerms
     * @property Coupons $Coupons
     * @property CronTasks $CronTasks
     * @property Currencies $Currencies
     * @property DataFeeds $DataFeeds
     * @property EmailGroups $EmailGroups
     * @property EmailVerifications $EmailVerifications
     * @property Emails $Emails
     * @property Encryption $Encryption
     * @property GatewayManager $GatewayManager
     * @property InvoiceTemplateManager $InvoiceTemplateManager
     * @property ElectronicInvoices $ElectronicInvoices
     * @property Invoices $Invoices
     * @property Languages $Languages
     * @property License $License
     * @property LicenseManager $LicenseManager
     * @property Logs $Logs
     * @property Marketplace $Marketplace
     * @property MessageGroups $MessageGroups
     * @property Messages $Messages
     * @property MessengerManager $MessengerManager
     * @property ModuleClientMeta $ModuleClientMeta
     * @property ModuleManager $ModuleManager
     * @property ModuleTypes $ModuleTypes
     * @property Navigation $Navigation
     * @property Notifications $Notifications
     * @property PackageGroups $PackageGroups
     * @property PackageOptionConditionSets $PackageOptionConditionSets
     * @property PackageOptionConditions $PackageOptionConditions
     * @property PackageOptionGroups $PackageOptionGroups
     * @property PackageOptions $PackageOptions
     * @property Packages $Packages
     * @property Payments $Payments
     * @property Permissions $Permissions
     * @property PluginManager $PluginManager
     * @property Pricings $Pricings
     * @property ReportManager $ReportManager
     * @property ServiceChanges $ServiceChanges
     * @property ServiceInvoices $ServiceInvoices
     * @property Services $Services
     * @property Settings $Settings
     * @property Staff $Staff
     * @property StaffGroups $StaffGroups
     * @property States $States
     * @property SystemEvents $SystemEvents
     * @property TaxProviders $TaxProviders
     * @property Taxes $Taxes
     * @property Themes $Themes
     * @property Transactions $Transactions
     * @property Users $Users
     * @property Quotations $Quotations
     * @property ManagedAccounts $ManagedAccounts
     * @property Blacklist $Blacklist
     * @property PasswordResets $PasswordResets
     * @property EmailHtmlTemplates $EmailHtmlTemplates
     *
     * Components
     * @property Download $Email
     * @property Email $Email
     * @property Input $Input
     * @property InvoiceDelivery $InvoiceDelivery
     * @property QuotationDelivery $QuotationDelivery
     * @property InvoiceFormats $InvoiceFormats
     * @property InvoiceTemplates $InvoiceTemplates
     * @property SettingsCollection $SettingsCollection
     * @property Upload $Upload
     * @property Record $Record
     * @property Session $Session
     * @property Upgrades $Upgrades
     * @property Net $Net
     * @property Security $Security
     *
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Css $Css
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class Model { /* Do Nothing */ }

    /**
     * Models
     * @property Accounts $Accounts
     * @property Actions $Actions
     * @property ApiKeys $ApiKeys
     * @property Backup $Backup
     * @property CalendarEvents $CalendarEvents
     * @property ClientGroups $ClientGroups
     * @property Clients $Clients
     * @property Companies $Companies
     * @property Contacts $Contacts
     * @property Countries $Countries
     * @property CouponPackageOptions $CouponPackageOptions
     * @property CouponTerms $CouponTerms
     * @property Coupons $Coupons
     * @property CronTasks $CronTasks
     * @property Currencies $Currencies
     * @property DataFeeds $DataFeeds
     * @property EmailGroups $EmailGroups
     * @property EmailVerifications $EmailVerifications
     * @property Emails $Emails
     * @property Encryption $Encryption
     * @property GatewayManager $GatewayManager
     * @property InvoiceTemplateManager $InvoiceTemplateManager
     * @property ElectronicInvoices $ElectronicInvoices
     * @property Invoices $Invoices
     * @property Languages $Languages
     * @property License $License
     * @property LicenseManager $LicenseManager
     * @property Logs $Logs
     * @property Marketplace $Marketplace
     * @property MessageGroups $MessageGroups
     * @property Messages $Messages
     * @property MessengerManager $MessengerManager
     * @property ModuleClientMeta $ModuleClientMeta
     * @property ModuleManager $ModuleManager
     * @property ModuleTypes $ModuleTypes
     * @property Navigation $Navigation
     * @property Notifications $Notifications
     * @property PackageGroups $PackageGroups
     * @property PackageOptionConditionSets $PackageOptionConditionSets
     * @property PackageOptionConditions $PackageOptionConditions
     * @property PackageOptionGroups $PackageOptionGroups
     * @property PackageOptions $PackageOptions
     * @property Packages $Packages
     * @property Payments $Payments
     * @property Permissions $Permissions
     * @property PluginManager $PluginManager
     * @property Pricings $Pricings
     * @property ReportManager $ReportManager
     * @property ServiceChanges $ServiceChanges
     * @property ServiceInvoices $ServiceInvoices
     * @property Services $Services
     * @property Settings $Settings
     * @property Staff $Staff
     * @property StaffGroups $StaffGroups
     * @property States $States
     * @property SystemEvents $SystemEvents
     * @property TaxProviders $TaxProviders
     * @property Taxes $Taxes
     * @property Themes $Themes
     * @property Transactions $Transactions
     * @property Users $Users
     * @property Quotations $Quotations
     * @property ManagedAccounts $ManagedAccounts
     * @property Blacklist $Blacklist
     * @property PasswordResets $PasswordResets
     * @property EmailHtmlTemplates $EmailHtmlTemplates
     *
     * Components
     * @property Download $Email
     * @property Email $Email
     * @property Input $Input
     * @property InvoiceDelivery $InvoiceDelivery
     * @property QuotationDelivery $QuotationDelivery
     * @property InvoiceFormats $InvoiceFormats
     * @property InvoiceTemplates $InvoiceTemplates
     * @property SettingsCollection $SettingsCollection
     * @property Upload $Upload
     * @property Record $Record
     * @property Session $Session
     * @property Upgrades $Upgrades
     * @property Net $Net
     * @property Security $Security
     *
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Css $Css
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class Gateway { /* Do Nothing */ }

    /**
     * Models
     * @property Accounts $Accounts
     * @property Actions $Actions
     * @property ApiKeys $ApiKeys
     * @property Backup $Backup
     * @property CalendarEvents $CalendarEvents
     * @property ClientGroups $ClientGroups
     * @property Clients $Clients
     * @property Companies $Companies
     * @property Contacts $Contacts
     * @property Countries $Countries
     * @property CouponPackageOptions $CouponPackageOptions
     * @property CouponTerms $CouponTerms
     * @property Coupons $Coupons
     * @property CronTasks $CronTasks
     * @property Currencies $Currencies
     * @property DataFeeds $DataFeeds
     * @property EmailGroups $EmailGroups
     * @property EmailVerifications $EmailVerifications
     * @property Emails $Emails
     * @property Encryption $Encryption
     * @property GatewayManager $GatewayManager
     * @property InvoiceTemplateManager $InvoiceTemplateManager
     * @property ElectronicInvoices $ElectronicInvoices
     * @property Invoices $Invoices
     * @property Languages $Languages
     * @property License $License
     * @property LicenseManager $LicenseManager
     * @property Logs $Logs
     * @property Marketplace $Marketplace
     * @property MessageGroups $MessageGroups
     * @property Messages $Messages
     * @property MessengerManager $MessengerManager
     * @property ModuleClientMeta $ModuleClientMeta
     * @property ModuleManager $ModuleManager
     * @property ModuleTypes $ModuleTypes
     * @property Navigation $Navigation
     * @property Notifications $Notifications
     * @property PackageGroups $PackageGroups
     * @property PackageOptionConditionSets $PackageOptionConditionSets
     * @property PackageOptionConditions $PackageOptionConditions
     * @property PackageOptionGroups $PackageOptionGroups
     * @property PackageOptions $PackageOptions
     * @property Packages $Packages
     * @property Payments $Payments
     * @property Permissions $Permissions
     * @property PluginManager $PluginManager
     * @property Pricings $Pricings
     * @property ReportManager $ReportManager
     * @property ServiceChanges $ServiceChanges
     * @property ServiceInvoices $ServiceInvoices
     * @property Services $Services
     * @property Settings $Settings
     * @property Staff $Staff
     * @property StaffGroups $StaffGroups
     * @property States $States
     * @property SystemEvents $SystemEvents
     * @property TaxProviders $TaxProviders
     * @property Taxes $Taxes
     * @property Themes $Themes
     * @property Transactions $Transactions
     * @property Users $Users
     * @property Quotations $Quotations
     * @property Blacklist $Blacklist
     * @property PasswordResets $PasswordResets
     * @property EmailHtmlTemplates $EmailHtmlTemplates
     *
     * Components
     * @property Download $Email
     * @property Email $Email
     * @property Input $Input
     * @property InvoiceDelivery $InvoiceDelivery
     * @property QuotationDelivery $QuotationDelivery
     * @property InvoiceFormats $InvoiceFormats
     * @property InvoiceTemplates $InvoiceTemplates
     * @property SettingsCollection $SettingsCollection
     * @property Upload $Upload
     * @property Record $Record
     * @property Session $Session
     * @property Upgrades $Upgrades
     * @property Net $Net
     * @property Security $Security
     *
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Css $Css
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class Module { /* Do Nothing */ }

    /**
     * Models
     * @property Accounts $Accounts
     * @property Actions $Actions
     * @property ApiKeys $ApiKeys
     * @property Backup $Backup
     * @property CalendarEvents $CalendarEvents
     * @property ClientGroups $ClientGroups
     * @property Clients $Clients
     * @property Companies $Companies
     * @property Contacts $Contacts
     * @property Countries $Countries
     * @property CouponPackageOptions $CouponPackageOptions
     * @property CouponTerms $CouponTerms
     * @property Coupons $Coupons
     * @property CronTasks $CronTasks
     * @property Currencies $Currencies
     * @property DataFeeds $DataFeeds
     * @property EmailGroups $EmailGroups
     * @property EmailVerifications $EmailVerifications
     * @property Emails $Emails
     * @property Encryption $Encryption
     * @property GatewayManager $GatewayManager
     * @property InvoiceTemplateManager $InvoiceTemplateManager
     * @property ElectronicInvoices $ElectronicInvoices
     * @property Invoices $Invoices
     * @property Languages $Languages
     * @property License $License
     * @property LicenseManager $LicenseManager
     * @property Logs $Logs
     * @property Marketplace $Marketplace
     * @property MessageGroups $MessageGroups
     * @property Messages $Messages
     * @property MessengerManager $MessengerManager
     * @property ModuleClientMeta $ModuleClientMeta
     * @property ModuleManager $ModuleManager
     * @property ModuleTypes $ModuleTypes
     * @property Navigation $Navigation
     * @property Notifications $Notifications
     * @property PackageGroups $PackageGroups
     * @property PackageOptionConditionSets $PackageOptionConditionSets
     * @property PackageOptionConditions $PackageOptionConditions
     * @property PackageOptionGroups $PackageOptionGroups
     * @property PackageOptions $PackageOptions
     * @property Packages $Packages
     * @property Payments $Payments
     * @property Permissions $Permissions
     * @property PluginManager $PluginManager
     * @property Pricings $Pricings
     * @property ReportManager $ReportManager
     * @property ServiceChanges $ServiceChanges
     * @property ServiceInvoices $ServiceInvoices
     * @property Services $Services
     * @property Settings $Settings
     * @property Staff $Staff
     * @property StaffGroups $StaffGroups
     * @property States $States
     * @property SystemEvents $SystemEvents
     * @property TaxProviders $TaxProviders
     * @property Taxes $Taxes
     * @property Themes $Themes
     * @property Transactions $Transactions
     * @property Users $Users
     * @property Quotations $Quotations
     * @property ManagedAccounts $ManagedAccounts
     * @property Blacklist $Blacklist
     * @property PasswordResets $PasswordResets
     * @property EmailHtmlTemplates $EmailHtmlTemplates
     *
     * Components
     * @property Download $Email
     * @property Email $Email
     * @property Input $Input
     * @property InvoiceDelivery $InvoiceDelivery
     * @property QuotationDelivery $QuotationDelivery
     * @property InvoiceFormats $InvoiceFormats
     * @property InvoiceTemplates $InvoiceTemplates
     * @property SettingsCollection $SettingsCollection
     * @property Upload $Upload
     * @property Record $Record
     * @property Session $Session
     * @property Upgrades $Upgrades
     * @property Net $Net
     * @property Security $Security
     *
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Css $Css
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class Plugin { /* Do Nothing */ }

    /**
     * Models
     * @property Accounts $Accounts
     * @property Actions $Actions
     * @property ApiKeys $ApiKeys
     * @property Backup $Backup
     * @property CalendarEvents $CalendarEvents
     * @property ClientGroups $ClientGroups
     * @property Clients $Clients
     * @property Companies $Companies
     * @property Contacts $Contacts
     * @property Countries $Countries
     * @property CouponTerms $CouponTerms
     * @property Coupons $Coupons
     * @property CronTasks $CronTasks
     * @property Currencies $Currencies
     * @property DataFeeds $DataFeeds
     * @property EmailGroups $EmailGroups
     * @property EmailVerifications $EmailVerifications
     * @property Emails $Emails
     * @property Encryption $Encryption
     * @property GatewayManager $GatewayManager
     * @property InvoiceTemplateManager $InvoiceTemplateManager
     * @property ElectronicInvoices $ElectronicInvoices
     * @property Invoices $Invoices
     * @property Languages $Languages
     * @property License $License
     * @property LicenseManager $LicenseManager
     * @property Logs $Logs
     * @property Marketplace $Marketplace
     * @property MessageGroups $MessageGroups
     * @property Messages $Messages
     * @property MessengerManager $MessengerManager
     * @property ModuleClientMeta $ModuleClientMeta
     * @property ModuleManager $ModuleManager
     * @property ModuleTypes $ModuleTypes
     * @property Navigation $Navigation
     * @property PackageGroups $PackageGroups
     * @property PackageOptionConditionSets $PackageOptionConditionSets
     * @property PackageOptionConditions $PackageOptionConditions
     * @property PackageOptionGroups $PackageOptionGroups
     * @property PackageOptions $PackageOptions
     * @property Packages $Packages
     * @property Payments $Payments
     * @property Permissions $Permissions
     * @property PluginManager $PluginManager
     * @property Pricings $Pricings
     * @property ReportManager $ReportManager
     * @property ServiceChanges $ServiceChanges
     * @property ServiceInvoices $ServiceInvoices
     * @property Services $Services
     * @property Settings $Settings
     * @property Staff $Staff
     * @property StaffGroups $StaffGroups
     * @property States $States
     * @property SystemEvents $SystemEvents
     * @property TaxProviders $TaxProviders
     * @property Taxes $Taxes
     * @property Themes $Themes
     * @property Transactions $Transactions
     * @property Users $Users
     * @property Quotations $Quotations
     * @property ManagedAccounts $ManagedAccounts
     * @property Blacklist $Blacklist
     * @property PasswordResets $PasswordResets
     * @property EmailHtmlTemplates $EmailHtmlTemplates
     *
     * Components
     * @property Download $Email
     * @property Email $Email
     * @property Input $Input
     * @property InvoiceDelivery $InvoiceDelivery
     * @property QuotationDelivery $QuotationDelivery
     * @property InvoiceFormats $InvoiceFormats
     * @property InvoiceTemplates $InvoiceTemplates
     * @property SettingsCollection $SettingsCollection
     * @property Upload $Upload
     * @property Record $Record
     * @property Session $Session
     * @property Upgrades $Upgrades
     * @property Net $Net
     * @property Security $Security
     *
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class Component { /* Do Nothing */ }

    /**
     * Helpers
     * @property Color $Color
     * @property CurrencyFormat $CurrencyFormat
     * @property Date $Date
     * @property DataStructure $DataStructure
     * @property Minphp\Form\Form $Form
     * @property Minphp\Html\Html $Html
     * @property Xml $Xml
     * @property Javascript $Javascript
     * @property Css $Css
     * @property Widget $Widget
     * @property WidgetClient $WidgetClient
     */
    abstract class View { /* Do Nothing */ }
}
