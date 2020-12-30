<?php
class StaticHtmlOutput_Cli extends StaticHtmlOutput
{
    protected $oembedUrls=[];

    public function generateArchiveOverride()
    {
        return $this->_generateArchive();
    }

    public function getOptionsOverride()
    {
        return $this->_options;
    }

	public static function resetInstance()
	{
        self::$_instance = null;
		self::$_instance = new static();
		self::$_instance->_options = new StaticHtmlOutput_Options(self::OPTIONS_KEY);
		self::$_instance->_view = new StaticHtmlOutput_View();
		return self::$_instance;
	}

	protected function prepareArchiveDirectory()
	{
    	global $blog_id;
		// Prepare archive directory
		$uploadDir = wp_upload_dir();
		$exporter = wp_get_current_user();
		$archiveName = $uploadDir['path'] . '/' . self::HOOK . '-' . $blog_id . '-' . time() . '-' . $exporter->user_login;
		$archiveDir = $archiveName . '/';
		if (!file_exists($archiveDir))
		{
			wp_mkdir_p($archiveDir);
		}

		return [$archiveName, $archiveDir];
	}

	protected function prepareQueue()
	{
		$baseUrl = untrailingslashit(home_url());
		$newBaseUrl = untrailingslashit($this->_options->getOption('baseUrl'));
		$urlsQueue = array_unique(array_merge(
			array(trailingslashit($baseUrl)),
			$this->_getListOfLocalFilesByUrl(array(get_template_directory_uri())),
			$this->_getListOfLocalFilesByUrl(explode("\n", $this->_options->getOption('additionalUrls')))
		));

		$urlsQueue[] = $baseUrl . '/feed/atom/';
		$urlsQueue[] = $baseUrl . '/projects/';

		return [$baseUrl, $newBaseUrl, $urlsQueue];
	}

	protected function addNewUrlsToQueue($urlsQueue, $urlResponse, $currentUrl, $baseUrl)
	{
// 	    var_dump(__FUNCTION__);
// 	    $r = new \ReflectionClass($urlResponse);
//         var_dump($r->getFilename());
// 	    exit;
        // Add new urls to the queue
        foreach ($urlResponse->extractAllUrls($baseUrl) as $newUrl)
        {
            if ($this->shouldAddUrlToQueue($newUrl, $currentUrl, $urlsQueue))
            {
                echo "    Adding ".$newUrl." to the list \n";
                $urlsQueue[] = $newUrl;
            }
        }
        return $urlsQueue;
	}

	protected function shouldAddUrlToQueue($newUrl, $currentUrl, $urlsQueue)
	{
        return (
                !isset($this->_exportLog[$newUrl])          &&
                $newUrl != $currentUrl                      &&
                !in_array($newUrl,$urlsQueue)               &&
                (strpos($newUrl, '&amp;title') === false)   &&
                (strpos($newUrl, '&amp;description') === false) &&
                (strpos($newUrl, 'wp-json/oembed/1.0/embed') === false) &&
                (strpos($newUrl, 'wp-atom.php') === false)
            );
	}

	protected function processOembedUrls($archiveDir)
	{
        foreach($this->oembedUrls as $newUrl=>$oldUrl)
        {
            $urlParts               = parse_url($oldUrl);
            parse_str($urlParts['query'], $params);
            $cmd =  '/usr/local/php5/bin/php -r ' . "'" .
                    '$_SERVER["REQUEST_URI"] = "' .
                    $urlParts['path'] .
                    '";' .
                    '$_SERVER["REQUEST_METHOD"] = "' .
                    'GET' .
                    '";' .
                    '$_GET["url"] = "' .
                    $params['url'] .
                    '";' .
                    'include "' .
                    get_home_path() . 'index.php";' . "'";
            $responseBody = `$cmd`;

            // $response = wp_remote_get($oldUrl,array('timeout'=>300));
            $file = $this->_getArchivePathFromUrlString($newUrl, false, $archiveDir);
            if (!file_exists(dirname($file)))
            {
                wp_mkdir_p(dirname($file));
            }
            // file_put_contents($file, $response['body']);
            file_put_contents($file, $responseBody);

        }
	}

	protected function processQueue($urlsQueue, $currentUrl, $baseUrl,
	    $archiveDir, $newBaseUrl)
	{
		$this->_exportLog = array();
        // $c=0;
		while ($currentUrl = array_shift($urlsQueue))
		{
			echo $currentUrl, "\n";

			$urlResponse = $this->instatiateUrlRequestObject($currentUrl);
			$urlResponse->cleanup();
			$this->oembedUrls = array_merge($this->oembedUrls, $urlResponse->oembedUrls);
            $this->oembedUrls = array_unique($this->oembedUrls);

			// Add current url to the list of processed urls
			$this->_exportLog[$currentUrl] = true;

			$urlsQueue = $this->addNewUrlsToQueue($urlsQueue, $urlResponse, $currentUrl, $baseUrl);

			// Save url data
			$urlResponse->replaceBaseUlr($baseUrl, $newBaseUrl);
			$this->_saveUrlData($urlResponse, $archiveDir);
            // $c++;
            // if($c > 150) { break; }
		}
		return $urlsQueue;
	}

	protected function instantiateZipArchive($archiveName)
	{
		$tempZip = $archiveName . '.tmp';
		$zipArchive = new ZipArchive();
		if ($zipArchive->open($tempZip, ZIPARCHIVE::CREATE) !== true)
		{
			return new WP_Error('Could not create archive');
		}

        return [$tempZip, $zipArchive];
	}

	protected function processArchiveDir($archiveDir, $zipArchive)
	{
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveDir));
		foreach ($iterator as $fileName => $fileObject)
		{
			$baseName = basename($fileName);
			if($baseName != '.' && $baseName != '..')
			{
				if (!$zipArchive->addFile(realpath($fileName), str_replace($archiveDir, '', $fileName)))
				{
					return new WP_Error('Could not add file: ' . $fileName);
				}
			}
		}
	}

	protected function createArchive($archiveName, $archiveDir)
	{
	    list($tempZip, $zipArchive) = $this->instantiateZipArchive($archiveName);
		$this->processArchiveDir($archiveDir, $zipArchive);
		$zipArchive->close();
		$archiveName .= '.zip';
		rename($tempZip, $archiveName);
		return $archiveName;
	}

	protected function _downloadFeedsAsIndexDotXml($archiveDir, $baseUrl, $newBaseUrl)
	{
		//go through and look for feed URLs and download them
        $feedUrls = array_filter(
            array_keys($this->_exportLog),
            function($item){
                return strpos($item, '/feed.xml') !== false;
            }
        );

        foreach($feedUrls as $currentUrl)
        {
            $currentUrl = preg_replace('%feed.xml$%','',$currentUrl);
            $urlResponse = $this->instatiateUrlRequestObject($currentUrl);
            $this->_saveUrlDataRss($urlResponse, $archiveDir, $baseUrl, $newBaseUrl);
        }

        //and our atom feed
        rename(
            $archiveDir . '/feed/atom/index.html',
            $archiveDir . '/feed/atom/feed.xml'
        );

        $this->swapUrlsInFile($archiveDir . '/feed/atom/feed.xml', $baseUrl, $newBaseUrl);
	}

	protected function _generateHtaccess($archiveDir)
	{
	    $htaccess = '
<IfModule mod_php7.c>
  php_value short_open_tag 1
</IfModule>

RewriteEngine On

#sends www.example.com to example.com
RewriteCond %{HTTP_HOST} ^www\.alanstorm\.com
RewriteRule ^(.*)$ http://alanstorm.com/$1 [R=301,L]

#START: tumblr posts
RewriteCond %{HTTP_HOST} magento-quickies\.alanstorm\.com [NC]
RewriteCond %{REQUEST_URI} ^/$
Rewriterule ^(.*)$ http://alanstorm.com/category/programming-quickies/ [L,R=301]

RewriteCond %{HTTP_HOST} ^magento-quickies\.alanstorm\.com [NC]
RewriteRule ^post/[0-9]+?/([^/]*)$ http://alanstorm.com/$1 [R=301,L]

RewriteCond %{HTTP_HOST} ^magento-quickies\.alanstorm\.com [NC]
RewriteRule ^post/[0-9]+?/([^/]*)/$ http://alanstorm.com/$1 [R=301,L]

RewriteCond %{HTTP_HOST} ^magento-quickies\.alanstorm\.com [NC]
RewriteRule ^post/[0-9]+?/([^/]*)/amp$ http://alanstorm.com/$1 [R=301,L]

RewriteCond %{HTTP_HOST} ^magento-quickies\.alanstorm\.com [NC]
RewriteRule ^rss/? http://alanstorm.com/category/programming-quickies/feed/feed.xml [R=301,L]

RewriteCond %{HTTP_HOST} ^magento-quickies\.alanstorm\.com [NC]
RewriteRule ^rss/? http://alanstorm.com/category/programming-quickies/feed/feed.xml [R=301,L]

RewriteCond %{HTTP_HOST} ^magento-quickies\.alanstorm\.com [NC]
RewriteRule ^(.*)$ http://alanstorm.com/$1 [R=301,L]
#END: tumblr posts

#force HTTPS

#RewriteCond %{SERVER_PORT} 80
#RewriteRule ^(.*)$ https://alanstorm.com/$1 [R,L]
RewriteCond %{SERVER_PORT} 80
RewriteCond %{HTTP_HOST} !^alanstorm.pairserver.com
RewriteRule ^(.*)$ https://alanstorm.com/$1 [R,L]

#people who mistakingly think we are a directory
#Old rules for old Content Courier application
#RewriteRule ^links/?$ /links/index.html [L]
#RewriteRule ^([^/]+)/$ /$1 [R=301,L]
#RewriteRule ^(tt4/[^/]+)/$ /$1 [R=301,L]

#Stupid Me
RewriteRule ^magneto_listener_lifecycle_block http://alanstorm.com/magento_listener_lifecycle_block [R=301,L]
RewriteRule ^magneto_2_object_manager_instance_objects http://alanstorm.com/magento_2_object_manager_instance_objects [R=301,L]

#START: Old rewrite rules that are still needed
#    RewriteRule ^apple_intel$ /tt4/apple_intel [L,R=301]
#    RewriteRule ^Apple_Store$ /tt4/Apple_Store [L,R=301]
#    RewriteRule ^applescript_eval$ /tt4/applescript_eval [L,R=301]
#    RewriteRule ^custom_cms$ /tt4/custom_cms [L,R=301]
#    RewriteRule ^designupdates$ /tt4/designupdates [L,R=301]
#    RewriteRule ^dhtml$ /tt4/dhtml [L,R=301]
#    RewriteRule ^email_forms$ /tt4/email_forms [L,R=301]
#    RewriteRule ^excel_tips1$ /tt4/excel_tips1 [L,R=301]
#    RewriteRule ^expiring_domain_name_snatching$ /tt4/expiring_domain_name_snatching [L,R=301]
#    RewriteRule ^flash$ /tt4/flash [L,R=301]
#    RewriteRule ^freebsd_basics$ /tt4/freebsd_basics [L,R=301]
#    RewriteRule ^google_are_s_m_r_t$ /tt4/google_are_s_m_r_t [L,R=301]
#    RewriteRule ^google_maps$ /tt4/google_maps [L,R=301]
#    RewriteRule ^housekeeping$ /tt4/housekeeping [L,R=301]
#    RewriteRule ^iebank1$ /tt4/iebank1 [L,R=301]
#    RewriteRule ^ipconfig_all$ /tt4/ipconfig_all [L,R=301]
#    RewriteRule ^launch$ /tt4/launch [L,R=301]
#    RewriteRule ^linkdump2004june2nd$ /tt4/linkdump2004june2nd [L,R=301]
#    RewriteRule ^local_w3c$ /tt4/local_w3c [L,R=301]
#    RewriteRule ^Macromedia_and_Adobe$ /tt4/Macromedia_and_Adobe [L,R=301]
#    RewriteRule ^mt_glossary$ /tt4/mt_glossary [L,R=301]
#    RewriteRule ^Omniweb$ /tt4/Omniweb [L,R=301]
#    RewriteRule ^OS_10_44$ /tt4/OS_10_44 [L,R=301]
#    RewriteRule ^OS_X_Routine_Maintenance$ /tt4/OS_X_Routine_Maintenance [L,R=301]
#    RewriteRule ^photoshop_actions_and_droplets$ /tt4/photoshop_actions_and_droplets [L,R=301]
#    RewriteRule ^phpnote11$ /tt4/phpnote11 [L,R=301]
#    RewriteRule ^RSS_Feed$ /tt4/RSS_Feed [L,R=301]
#    RewriteRule ^rss_links_fixed$ /tt4/rss_links_fixed [L,R=301]
#    RewriteRule ^Ruby_on_Rails_setup_tutorial$ /tt4/Ruby_on_Rails_setup_tutorial [L,R=301]
#    RewriteRule ^Safari_FAQ$ /tt4/Safari_FAQ [L,R=301]
#    RewriteRule ^stupid_numbering_conventions$ /tt4/stupid_numbering_conventions [L,R=301]
#    RewriteRule ^Stupid_Product_Pricing$ /tt4/Stupid_Product_Pricing [L,R=301]
#    RewriteRule ^the_dilemma_of_advertising_$ /tt4/the_dilemma_of_advertising_ [L,R=301]
#    RewriteRule ^The_Strange_Zen_Of_JavaScript$ /tt4/The_Strange_Zen_Of_JavaScript [L,R=301]
#    RewriteRule ^usgs_photography$ /tt4/usgs_photography [L,R=301]
#    RewriteRule ^virtual_pc$ /tt4/virtual_pc [L,R=301]
#    RewriteRule ^W3C_validator_on_OS_X$ /tt4/W3C_validator_on_OS_X [L,R=301]
#    RewriteRule ^webkit2png$ /tt4/webkit2png [L,R=301]
#    RewriteRule ^whatismyip$ /tt4/whatismyip [L,R=301]
#    RewriteRule ^when_to_buy_a_site$ /tt4/when_to_buy_a_site [L,R=301]
#    RewriteRule ^windows_xp_dos_window$ /tt4/windows_xp_dos_window [L,R=301]
#    RewriteRule ^xtr_frontpage$ /tt4/xtr_frontpage [L,R=301]

    #old old crap
    RewriteRule ^dmg/PHP_Prototype.dmg /2005/projects/PHP_Prototype.dmg [R=301,L]
    RewriteRule ^contact.html /site/contact [R=301,L]
    RewriteRule ^projects.html /site/projects [R=301,L]
    RewriteRule ^resume.doc /site/resume [R=301,L]
    RewriteRule ^resume.html /resume [R=301,L]

    RewriteRule ^home/{0,1}$ / [L,R=301]
    RewriteRule ^site/{0,1}$ / [L,R=301]
    RewriteRule ^site/([a-zA-Z0-9_]+)/*$ /$1 [L,R=301]
#END: Old rewrite rules that are still needed

#START: WordPress casing issue
    RewriteRule ^10_4_Upgrade /10_4_upgrade [L,R=301]
    RewriteRule ^Centered /centered [L,R=301]
    RewriteRule ^Five_Firefox_Extensions /five_firefox_extensions [L,R=301]
    RewriteRule ^How_Odd___ /how_odd___ [L,R=301]
    RewriteRule ^OS_X_10_4_and_transmit_22 /os_x_10_4_and_transmit_22 [L,R=301]
#END: WordPress casing issue

#START: Reverse of previous rewrites, in order to hew to WordPress worldview
#START: also handles casing
RewriteRule ^tt4/apple_intel /apple_intel [L,R=301]
RewriteRule ^tt4/Apple_Store /Apple_Store [L,R=301]
RewriteRule ^Apple_Store /apple_store [L,R=301]
RewriteRule ^tt4/applescript_eval /applescript_eval [L,R=301]
RewriteRule ^tt4/custom_cms /custom_cms [L,R=301]
RewriteRule ^tt4/designupdates /designupdates [L,R=301]
RewriteRule ^tt4/dhtml /dhtml [L,R=301]
RewriteRule ^tt4/email_forms /email_forms [L,R=301]
RewriteRule ^tt4/excel_tips1 /excel_tips1 [L,R=301]
RewriteRule ^tt4/expiring_domain_name_snatching /expiring_domain_name_snatching [L,R=301]
RewriteRule ^tt4/flash /flash [L,R=301]
RewriteRule ^tt4/freebsd_basics /freebsd_basics [L,R=301]
RewriteRule ^tt4/google_are_s_m_r_t /google_are_s_m_r_t [L,R=301]
RewriteRule ^tt4/google_maps /google_maps [L,R=301]
RewriteRule ^tt4/housekeeping /housekeeping [L,R=301]
RewriteRule ^tt4/iebank1 /iebank [L,R=301]
RewriteRule ^tt4/ipconfig_all /ipconfig_all [L,R=301]
RewriteRule ^tt4/launch /launch [L,R=301]
RewriteRule ^tt4/linkdump2004june2nd /linkdump2004june2nd [L,R=301]
RewriteRule ^tt4/local_w3c /local_w3c [L,R=301]
RewriteRule ^tt4/Macromedia_and_Adobe /Macromedia_and_Adobe [L,R=301]
RewriteRule ^Macromedia_and_Adobe /macromedia_and_adobe [L,R=301]
RewriteRule ^tt4/mt_glossary /mt_glossary [L,R=301]
RewriteRule ^tt4/Omniweb /Omniweb [L,R=301]
RewriteRule ^Omniweb /omniweb [L,R=301]
RewriteRule ^tt4/OS_10_44 /OS_10_44 [L,R=301]
RewriteRule ^OS_10_44 /os_10_44 [L,R=301]
RewriteRule ^tt4/OS_X_Routine_Maintenance /OS_X_Routine_Maintenance [L,R=301]
RewriteRule ^OS_X_Routine_Maintenance /os_x_routine_maintenance [L,R=301]
RewriteRule ^tt4/photoshop_actions_and_droplets /photoshop_actions_and_droplets [L,R=301]
RewriteRule ^tt4/phpnote11 /phpnote11 [L,R=301]
RewriteRule ^tt4/RSS_Feed /RSS_Feed [L,R=301]
RewriteRule ^RSS_Feed /rss_feed [L,R=301]
RewriteRule ^tt4/rss_links_fixed /rss_links_fixed [L,R=301]
RewriteRule ^tt4/Ruby_on_Rails_setup_tutorial /Ruby_on_Rails_setup_tutorial [L,R=301]
RewriteRule ^Ruby_on_Rails_setup_tutorial /ruby_on_rails_setup_tutorial [L,R=301]
RewriteRule ^tt4/Safari_FAQ /Safari_FAQ [L,R=301]
RewriteRule ^Safari_FAQ /safari_faq [L,R=301]
RewriteRule ^tt4/stupid_numbering_conventions /stupid_numbering_conventions [L,R=301]
RewriteRule ^tt4/Stupid_Product_Pricing /Stupid_Product_Pricing [L,R=301]
RewriteRule ^Stupid_Product_Pricing /stupid_product_pricing [L,R=301]
RewriteRule ^tt4/the_dilemma_of_advertising_ /the_dilemma_of_advertising_ [L,R=301]
RewriteRule ^tt4/The_Strange_Zen_Of_JavaScript /The_Strange_Zen_Of_JavaScript [L,R=301]
RewriteRule ^The_Strange_Zen_Of_JavaScript /the_strange_zen_of_javascript [L,R=301]
RewriteRule ^tt4/usgs_photography /usgs_photography [L,R=301]
RewriteRule ^tt4/virtual_pc /virtual_pc [L,R=301]
RewriteRule ^tt4/W3C_validator_on_OS_X /W3C_validator_on_OS_X [L,R=301]
RewriteRule ^W3C_validator_on_OS_X /w3c_validator_on_os_x [L,R=301]
RewriteRule ^tt4/webkit2png /webkit2png [L,R=301]
RewriteRule ^tt4/whatismyip /whatismyip [L,R=301]
RewriteRule ^tt4/when_to_buy_a_site /when_to_buy_a_site [L,R=301]
RewriteRule ^tt4/windows_xp_dos_window /windows_xp_dos_window [L,R=301]
RewriteRule ^tt4/xtr_frontpage /xtr_frontpage [L,R=301]

RewriteRule ^tt4/_netrc_and_FTP /_netrc_and_ftp [L,R=301]
RewriteRule ^tt4/_Smart_Quotes_ /_smart_quotes_  [L,R=301]
RewriteRule ^tt4/address_fields /address_fields [L,R=301]
RewriteRule ^tt4/apple_hardware_installation_instructions /apple_hardware_installation_instructions [L,R=301]
RewriteRule ^tt4/apple_mail_extras /apple_mail_extras [L,R=301]
RewriteRule ^tt4/archives /archives [L,R=301]
RewriteRule ^tt4/BSD_Hardware /bsd_hardware [L,R=301]
RewriteRule ^tt4/greylisting /greylisting [L,R=301]

#END: Reverse of previous rewrites, in order to hew to WordPress worldview
#END: also handles casing

RewriteRule ^atom /feed/atom/feed.xml [L]
RewriteRule ^magento_module_creator_ultimate_review /magento_ultimate_module_creator_review [L,R=301]

RewriteRule ^site/about /about
RewriteRule ^site/archives /archives
RewriteRule ^site/contact /contact
RewriteRule ^site/home /
RewriteRule ^feeds/alanstorm.xml /feed/feed.xml [L,R=301]
RewriteRule ^category/orocrm /category/oro [L,R=301]
RewriteRule ^category/orocrm/ /category/oro [L,R=301]

#RewriteCond %{REQUEST_FILENAME} !-f
#RewriteCond %{REQUEST_FILENAME} !-d
#    RewriteRule ^(.*)$ index.php?uri=$1 [L,QSA]
';
        file_put_contents($archiveDir . '.htaccess', $htaccess);
	}

	protected function _generateArchive()
	{
		set_time_limit(0);
		list($archiveName, $archiveDir) = $this->prepareArchiveDirectory();

        $this->_generateHtaccess($archiveDir);

		// Prepare queue
        list($baseUrl, $newBaseUrl, $urlsQueue) = $this->prepareQueue();

		// Process queue
		$urlsQueue = $this->processQueue($urlsQueue, $currentUrl, $baseUrl,
	         $archiveDir, $newBaseUrl);

		//grab all feeds
        $this->_downloadFeedsAsIndexDotXml($archiveDir, $baseUrl, $newBaseUrl);

		//grab all oembeds
        // $this->processOembedUrls($archiveDir);

		// Create archive object
		$archiveName = $this->createArchive($archiveName, $archiveDir);
		return str_replace(ABSPATH, trailingslashit(home_url()), $archiveName);

	}

	protected function _getArchivePathFromUrlString($url, $isHtml, $archiveDir)
	{
		$urlInfo = parse_url($url);
		$pathInfo = pathinfo(isset($urlInfo['path']) && $urlInfo['path'] != '/' ? $urlInfo['path'] : 'index.html');

        // Prepare file directory and create it if it doesn't exist
		$fileDir = $archiveDir . (isset($pathInfo['dirname']) ? $pathInfo['dirname'] : '');
		if (empty($pathInfo['extension']) && $pathInfo['basename'] == $pathInfo['filename'])
		{
			$fileDir .= '/' . $pathInfo['basename'];
			$pathInfo['filename'] = 'index';
		}

        // Prepare file name and save file contents
		$fileExtension = ($isHtml || !isset($pathInfo['extension']) ? 'html' : $pathInfo['extension']);
		$fileName = $fileDir . '/' . $pathInfo['filename'] . '.' . $fileExtension;
		return $fileName;
	}

	protected function _getArchivePathFromUrl(StaticHtmlOutput_UrlRequest $url, $archiveDir)
	{
	    return $this->_getArchivePathFromUrlString($url->getUrl(), $url->isHtml(), $archiveDir);
	}

	protected function _saveUrlDataRss(StaticHtmlOutput_UrlRequest $url, $archiveDir, $baseUrl, $newBaseUrl)
	{
	    $this->_saveUrlData($url, $archiveDir);
	    $path = $this->_getArchivePathFromUrl($url, $archiveDir);
	    rename($path, dirname($path) . '/feed.xml');

	    $this->swapUrlsInFile(dirname($path) . '/feed.xml', $baseUrl, $newBaseUrl);
	}

    protected function swapUrlsInFile($file, $baseUrl, $newBaseUrl)
    {
        $contents = file_get_contents($file);
        $contents = str_replace($baseUrl, $newBaseUrl, $contents);
        file_put_contents($file, $contents);
    }

	protected function instatiateUrlRequestObject($currentUrl)
	{
    	return new StaticHtmlOutput_UrlRequest_Cli($currentUrl);
	}
}
