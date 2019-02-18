<?php

/**
 * Class LocalValetDriver
 * inspired by:
 * https://github.com/fewagency/best-practices/blob/master/Wordpress/WordPressMultisiteValetDriver.php
 */
class LocalValetDriver extends WordPressValetDriver
{
    /**
     * @var string The public web directory, if deeper under the root directory
     */
    protected $public_dir = '';
    /**
     * @var bool true if site is detected to be multisite
     */
    protected $multisite = false;

    /**
     * The file to check for when determining
     * whether to use this driver.
     *
     * Should contain a single line: the URL
     * that proxy requests will be sent to.
     *
     * @var string
     */
    protected $remoteProxy = '.uploads-proxy';

    /**
     * Determine if the driver serves the request.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return bool
     */
    public function serves($sitePath, $siteName, $uri)
    {
        foreach (['', 'public'] as $public_directory) {
            if ($this->checkPublicDir($sitePath, $public_directory)) {
                return true;
            }
        }
        return false;
    }

    protected function checkPublicDir($sitePath, $public_directory)
    {
        $this->public_dir = $public_directory;
        $wp_config_path = $this->realSitePath($sitePath) . '/wp-config.php';
        if (file_exists($wp_config_path)) {
            $this->checkMultisite($sitePath, $wp_config_path);
            return true;
        }
        return false;
    }

    /**
     * Translate the site path to the actual public directory
     *
     * @param $sitePath
     * @return string
     */
    protected function realSitePath($sitePath)
    {
        if ($this->public_dir) {
            $sitePath .= '/' . $this->public_dir;
        }
        return $sitePath;
    }

    protected function checkMultisite($sitePath, $wp_config_path)
    {
        // Look for define('MULTISITE', true in wp-config
        $env_path = $sitePath . '/.env';
        if (
            preg_match("/^define\(\s*('|\")MULTISITE\\1\s*,\s*true\s*\)/mi", file_get_contents($wp_config_path)) ||
            (file_exists($env_path) and preg_match("/^WP_MULTISITE=true$/mi", file_get_contents($env_path)))
        ) {
            $this->multisite = true;
        }
    }

    /**
     * Determine if the incoming request is for a static file.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return string|false
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        $uri = $this->rewriteMultisite($uri);
        $sitePath = $this->realSitePath($sitePath);
        if ($this->shouldProxy($uri) && !$this->isActualFile($staticFilePath = $sitePath . $uri)) {
            return $staticFilePath;
        }
        return parent::isStaticFile($sitePath, $siteName, $uri);
    }

    /**
     * Imitate the rewrite rules for a multisite .htaccess
     *
     * @param $uri
     * @return string
     */
    protected function rewriteMultisite($uri)
    {
        $uri = $this->forceTrailingSlash($uri);

        if ($this->multisite) {
            if (preg_match('/^(.*)?(\/wp-(content|admin|includes).*)/', $uri, $matches)) {
                //RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
                $uri = $matches[2];
            } elseif (preg_match('/^(.*)?(\/.*\.php)$/', $uri, $matches)) {
                //RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
                $uri = $matches[2];
            }
        }
        return $uri;
    }

    /**
     * Redirect to uri with trailing slash.
     *
     * @param  string $uri
     * @return string
     */
    private function forceTrailingSlash($uri)
    {
        if (substr($uri, -1 * strlen('/wp-admin')) == '/wp-admin') {
            header('Location: ' . $uri . '/');
            die;
        }
        return $uri;
    }

    /**
     * Determine if the URI should be proxied.
     *
     * @param $uri
     * @return bool
     */
    public function shouldProxy($uri)
    {
        $dirName = pathinfo($uri, PATHINFO_DIRNAME);

        return strpos($dirName, 'wp-content/uploads') !== false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return string
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        $uri = $this->rewriteMultisite($uri);
        $sitePath = $this->realSitePath($sitePath);
        return parent::frontControllerPath($sitePath, $siteName, $uri);
    }

    /**
     * Serve the static file at the given path.
     *
     * @param string $staticFilePath
     * @param string $sitePath
     * @param string $siteName
     * @param string $uri
     */
    public function serveStaticFile($staticFilePath, $sitePath, $siteName, $uri)
    {
        if ($this->shouldProxy($uri) && !file_exists($staticFilePath) && $this->hasUploadProxy($sitePath)) {
            $proxy = $this->getProxyUrl($sitePath);

            header('Location: ' . $proxy . $uri);

            return;
        }

        parent::serveStaticFile($staticFilePath, $sitePath, $siteName, $uri);
    }

    protected function hasUploadProxy($sitePath)
    {
        return file_exists($sitePath . '/' . $this->remoteProxy);
    }

    /**
     * Get the URL from the config file.
     *
     * @param $sitePath
     * @return string
     */
    protected function getProxyUrl($sitePath)
    {
        $proxy = rtrim(file_get_contents($sitePath . '/' . $this->remoteProxy), '/');
        return rtrim($proxy);
    }
}
