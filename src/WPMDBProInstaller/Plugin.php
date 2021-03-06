<?php namespace Harmonic\WPMDBProInstaller;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Dotenv\Dotenv;
use Harmonic\WPMDBProInstaller\Exceptions\MissingKeyException;
use Harmonic\WPMDBProInstaller\Exceptions\MissingSiteUrlException;

/**
 * A composer plugin that makes installing ACF PRO possible
 *
 * The WordPress plugin Advanced Custom Fields PRO (ACF PRO) does not
 * offer a way to install it via composer natively.
 *
 * This plugin uses a 'package' repository (user supplied) that downloads the
 * correct version from the ACF site using the version number from
 * that repository and a license key from the ENVIRONMENT or an .env file.
 *
 * With this plugin user no longer need to expose their license key in
 * composer.json.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The name of the environment variable
     * where the ACF PRO key should be stored.
     */
    const KEY_ENV_VARIABLE = 'WP_MIGRATE_DB_PRO_KEY';
    const SITE_ENV_VARIABLE = 'APP_URL';

    /**
     * The name of the ACF PRO package
     */
    const ACF_PRO_PACKAGE_NAME =
    'deliciousbrains/wp-migrate-db-pro';

    /**
     * The url where ACF PRO can be downloaded (without filename, version and key)
     */
    const ACF_PRO_PACKAGE_URL =
    'https://deliciousbrains.com/dl/';

    /**
     * @access protected
     * @var Composer
     */
    protected $composer;

    /**
     * @access protected
     * @var IOInterface
     */
    protected $io;

    /**
     * The function that is called when the plugin is activated
     *
     * Makes composer and io available because they are needed
     * in the addParams method.
     *
     * @access public
     * @param Composer $composer The composer object
     * @param IOInterface $io Not used
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Subscribe this Plugin to relevant Events
     *
     * Pre Install/Update: The version needs to be added to the url
     *                     (will show up in composer.lock)
     * Pre Download: The key needs to be added to the url
     *               (will not show up in composer.lock)
     *
     * @access public
     * @return array An array of events that the plugin subscribes to
     * @static
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::PRE_PACKAGE_INSTALL => 'addVersion',
            PackageEvents::PRE_PACKAGE_UPDATE => 'addVersion',
            PluginEvents::PRE_FILE_DOWNLOAD => 'addParams'
        ];
    }

    /**
     * Add the version to the package url
     *
     * The version needs to be added in the PRE_PACKAGE_INSTALL/UPDATE
     * event to make sure that different version save different urls
     * in composer.lock. Composer would load any available version from cache
     * although the version numbers might differ (because they have the same
     * url).
     *
     * @access public
     * @param PackageEvent $event The event that called the method
     * @throws UnexpectedValueException
     */
    public function addVersion(PackageEvent $event)
    {
        $package = $this->getPackageFromOperation($event->getOperation());

        if ($package->getName() === self::ACF_PRO_PACKAGE_NAME) {
            $version = $this->validateVersion($package->getPrettyVersion());
            $package->setDistUrl(
                //$this->addParameterToUrl($package->getDistUrl(), 't', $version)
                $this->urlManager($package->getDistUrl(), $version)
            );
        }
    }


    /**
     * Add the key from the environment to the event url
     *
     * The key is not added to the package because it would show up in the
     * composer.lock file in this case. A custom file system is used to
     * swap out the ACF PRO url with a url that contains the key.
     *
     * @access public
     * @param PreFileDownloadEvent $event The event that called this method
     * @throws MissingKeyException
     */
    public function addParams(PreFileDownloadEvent $event)
    {
        $processedUrl = $event->getProcessedUrl();

        if ($this->isAcfProPackageUrl($processedUrl)) {
            $rfs = $event->getRemoteFilesystem();
            
            $url = $this->addParameterToUrl(
                $processedUrl,
                'licence_key',
                $this->getKeyFromEnv()
            );
            $url = $this->addParameterToUrl(
                $url,
                'site_url',
                $this->getSiteUrlFromEnv()
            );
            
            $acfRfs = new RemoteFilesystem(
                $url,
                $this->io,
                $this->composer->getConfig(),
                $rfs->getOptions(),
                $rfs->isTlsDisabled()
            );
            $event->setRemoteFilesystem($acfRfs);
        }
    }

    /**
     * Get the package from a given operation
     *
     * Is needed because update operations don't have a getPackage method
     *
     * @access protected
     * @param OperationInterface $operation The operation
     * @return PackageInterface The package of the operation
     */
    protected function getPackageFromOperation(OperationInterface $operation)
    {
        if ($operation->getJobType() === 'update') {
            return $operation->getTargetPackage();
        }
        return $operation->getPackage();
    }

    /**
     * Generate a valid url based on version requested
     * 
     * @param String $url The URL provided by composer.json
     * @param String $version The version provided by composer.json
     * @return String The URL based on original request and version supplied
     */
    public function urlManager(String $url, String $version) { //TODO: Make this protected and alter tests

        $processed_url = "https://deliciousbrains.com/dl/";

        if (strpos($url, 'wp-migrate-db-pro-media-files') !== false) {
            $plugin = "wp-migrate-db-pro-media-files-";
        } elseif (strpos($url, 'wp-migrate-db-pro-cli') !== false) {
            $plugin = "wp-migrate-db-pro-cli-";
        } else {
            // Assume its the overall plugin
            $plugin = "wp-migrate-db-pro-";
        }

        if ($version == "*") {
            // Latest
            $version="latest";
        } 

        return $processed_url . $plugin . $version . ".zip";
    }
    
    /**
     * Validate that the version is an exact major.minor.patch.optional version
     *
     * The url to download the code for the package only works with exact
     * version numbers with 3 or 4 digits: e.g. 1.2.3 or 1.2.3.4
     *
     * @access protected
     * @param string $version The version that should be validated
     * @return string The valid version
     * @throws UnexpectedValueException
     */
    protected function validateVersion($version)
    {
        if ($version == "*") {
            return true;
        }
        
        // \A = start of string, \Z = end of string
        // See: http://stackoverflow.com/a/34994075
        $major_minor_patch_optional = '/\A\d\.\d\.\d{1,2}(?:\.\d)?\Z/';

        if (!preg_match($major_minor_patch_optional, $version)) {
            throw new \UnexpectedValueException(
                'The version constraint of ' . self::ACF_PRO_PACKAGE_NAME .
                ' should be exact (with 3 or 4 digits). ' .
                'Invalid version string "' . $version . '"'
            );
        }

        return $version;
    }

    /**
     * Test if the given url is the ACF PRO download url
     *
     * @access protected
     * @param string The url that should be checked
     * @return bool
     */
    protected function isAcfProPackageUrl($url)
    {
        return strpos($url, self::ACF_PRO_PACKAGE_URL) !== false;
    }

    /**
     * Get the ACF PRO key from the environment
     *
     * Loads the .env file that is in the same directory as composer.json
     * and gets the key from the environment variable KEY_ENV_VARIABLE.
     * Already set variables will not be overwritten by the variables in .env
     * @link https://github.com/vlucas/phpdotenv#immutability
     *
     * @access protected
     * @return string The key from the environment
     * @throws Harmonic\WPMDBProInstaller\Exceptions\MissingKeyException
     */
    protected function getKeyFromEnv()
    {
        $this->loadDotEnv();
        $key = getenv(self::KEY_ENV_VARIABLE);

        if (!$key) {
            throw new MissingKeyException(self::KEY_ENV_VARIABLE);
        }

        return $key;
    }

    /**
     * Get the site URL from the environment
     *
     * @return void
     */
    public function getSiteUrlFromEnv() //TODO: Make this protected and alter tests
    {
        $this->loadDotEnv();
        $url = getenv(self::SITE_ENV_VARIABLE);

        if (empty($url)) {
            throw new MissingSiteUrlException(self::SITE_ENV_VARIABLE);
        }

        // Remove http:// or https:// from APP_URL
        $prefix = "http://";
        $prefix_s = "https://";
        if (substr($url, 0, strlen($prefix)) == $prefix) {
            $url = substr($url, strlen($prefix));
        } elseif (substr($url, 0, strlen($prefix_s)) == $prefix_s) {
            $url = substr($url, strlen($prefix_s));
        }

        return $url;
    }

    /**
     * Make environment variables in .env available if .env exists
     *
     * getcwd() returns the directory of composer.json.
     *
     * @access protected
     */
    protected function loadDotEnv()
    {
        if (file_exists(getcwd().DIRECTORY_SEPARATOR.'.env')) {
            $dotenv = new Dotenv(getcwd());
            $dotenv->load();
        }
    }

    /**
     * Add a parameter to the given url
     *
     * Adds the given parameter at the end of the given url.
     *
     * @access protected
     * @param string $url The url that should be appended
     * @param string $parameter The name of the parameter
     * @param string $value The value of the parameter
     * @return string The url appended with &parameter=value
     */
    protected function addParameterToUrl($url, $parameter, $value)
    {
        $cleanUrl = $this->removeParameterFromUrl($url, $parameter);
        
        $url = explode( '?', $cleanUrl );
        if (sizeof($url)>1) {
            // already have a ?
            $joiner = "&";
        } else {
            $joiner = "?";
        }

        $urlParameter = $joiner . $parameter . '=' . urlencode($value);

        return $cleanUrl .= $urlParameter;
    }

    /**
     * Remove a given parameter from the given url
     *
     * Removes &parameter=value from the given url. Only works with urls that
     * have multiple parameters and the parameter that should be removed is
     * not the first (because of the & character).
     *
     * @access protected
     * @param string $url The url where the parameter should be removed
     * @param string $parameter The name of the parameter
     * @return string The url with the &parameter=value removed
     */
    protected function removeParameterFromUrl($url, $parameter)
    {
        // e.g. &t=1.2.3 in example.com?p=index.php&t=1.2.3&k=key
        $pattern = "/(&$parameter=[^&]*)/";
        return preg_replace($pattern, '', $url);
    }
}
