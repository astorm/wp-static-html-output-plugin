<?php
class StaticHtmlOutput_UrlRequest_Cli extends StaticHtmlOutput_UrlRequest
{
    const TYPE_STATIC_WP_CONTENT    = 'static-wp-content';
    const TYPE_STATIC_WP_INCLUDES   = 'static-wp-includes';
    
    const TYPE_HOMEPAGE             = 'homepage';
    const TYPE_FEED                 = 'feed';
    const TYPE_PAGE_OR_POST         = 'page-or-post';
    
    const TYPE_LISTING_CATEGORY     = 'listing-category';
    const TYPE_LISTING_AUTHOR       = 'listing-author';
    const TYPE_LISTING_SERIES       = 'listing-series';
    const TYPE_LISTING_DATE         = 'listing-date';  
    
    const TYPE_WP_JSON              = 'wp-json';    
    
	public function __construct($url)
	{
    	$this->_url = filter_var($url, FILTER_VALIDATE_URL);

        //a static file that would skip wordpress
        if($this->isStaticFileUrl($this->_url))
        {
            return $this->initStaticFile($this->_url); 
        }

        //a wordpress generated file we can generate locally
        if($this->isWordpressFileUrl($this->url))
        {
            return $this->initWordpressFile($this->_url);
        }

		return parent::__construct($url);
	}

    protected function isWordpressFileUrl($url)
    {
        return in_array($requestType, 
            [   self::TYPE_HOMEPAGE,self::TYPE_FEED,self::TYPE_PAGE_OR_POST,
                self::TYPE_LISTING_CATEGORY,self::TYPE_LISTING_AUTHOR,
                self::TYPE_LISTING_SERIES,self::TYPE_LISTING_DATE,
                self::TYPE_WP_JSON,]);
    }
    
    protected function isStaticFileUrl($url)
    {
    	$requestType = $this->getUrlType($this->_url);
        return in_array($requestType, 
            [self::TYPE_STATIC_WP_CONTENT, self::TYPE_STATIC_WP_INCLUDES]);
    }
    
    protected function getFileTypeContentTypeMap()
    {
        return['.js'=>'application/javascript',
        '.css'=>'text/css',
        '.eot'=>'application/vnd.ms-fontobject',
        '.svg'=>'image/svg+xml',
        '.ttf'=>'application/x-font-ttf',
        '.woff'=>'application/x-font-woff',
        '.otf'=>'application/x-font-otf',
        '.png'=>'image/png',
        '.zip'=>'application/zip',
        '.mo'=>'application/gettext',
        '.po'=>'text/gettext',
        '.txt'=>'text/plain',
        '.scss'=>'text/css'];
    }
    
    protected function getUrlPartsWithoutDomain($url)
    {
        $parts = preg_split('%https?://%i',$url);
        $parts = array_filter($parts);
        $parts = explode('/',implode('',$parts));
        $domain = array_shift($parts);
        $parts = implode('/', $parts);
        $parts = explode('.', $parts);
        return $parts;    
    }
    
    protected function checkContentTypeCap($url)
    {
        $map = $this->getFileTypeContentTypeMap();        
        foreach($map as $extension=>$content_type)
        {
            if(strpos($url, $extension) !== false)
            {
                return $content_type;
            }        
        }
        return null;    
    }
    
    protected function getContentTypeFromUrl($url)
    {
        $parts = $this->getUrlPartsWithoutDomain($url);
        
        if($content_type = $this->checkContentTypeCap($url))
        {
            return $content_type;
        }
                
        if(count($parts) < 2)
        {
            return 'text/html';
        }

        if(strpos($url, 'wp-json/') !== false)
        {
            return 'application/javascript';
        }                      
        
        var_dump($parts);                      
        throw new \Exception("Unknown type in $url " . __METHOD__);
    }
    
    protected function generateCommandLineStringForStaticGeneration($url)
    {
        $urlParts               = parse_url($url);
        $cmd =  '/usr/local/php5/bin/php -r ' . "'" .
                '$_SERVER["REQUEST_URI"] = "' . 
                $urlParts['path'] . 
                '";include "' . 
                get_home_path() . 'index.php";' . "'";        
        return $cmd;        
    }
    
    protected function initWordpressFile($url)
    {
        $cmd = $this->generateCommandLineStringForStaticGeneration($url);
  
        $response               = $this->getFakeBlankResponse();
        $response['body']       = `$cmd`;
        $response['headers']['content-type'] = $this->getContentTypeFromUrl($url);
        $this->_response        = $response;
    }
    
    protected function initStaticFile($url)
    {
        $urlParts               = parse_url($url);
        $filePath               = get_home_path() . $urlParts['path'];        
        $response               = $this->getFakeBlankResponse();
        $response['body']       = file_get_contents($filePath);
        $response['headers']['content-type'] = $this->getContentTypeFromUrl($url);
        $this->_response        = $response;
    }
    
    protected function getFakeBlankResponse()
    {
    	return [
    	    'is_fake'=>true,
    	    'headers'=> [
    	        'date'          =>'',
    	        'server'        =>'',
    	        'x-powered-by'  =>'',
    	        'content-type'  =>''
    	    ],
    	    'body'   =>''
    	];    
    }
		                  
    protected function getUrlType($url)
    {
        $parts = parse_url($url);
    
        //homepage
        if($parts['path'] === '/')
        {
            return self::TYPE_HOMEPAGE;
        }
    
        
        $pathParts = explode('/', $parts['path']);
        $pathParts = array_values(array_filter($pathParts));
        
        //static content
        if($pathParts[0] === 'wp-content')
        {
            return self::TYPE_STATIC_WP_CONTENT;
        }    
        if($pathParts[0] === 'wp-includes')
        {
            return self::TYPE_STATIC_WP_INCLUDES;
        }

        //JSON that snuck through
        if($pathParts[0] === 'wp-json')
        {
            return self::TYPE_WP_JSON;
        }
     
        //category, author, series listings
        if($pathParts[0] === 'category')
        {
            return self::TYPE_LISTING_CATEGORY;
        }     
        if($pathParts[0] === 'author')
        {
            return self::TYPE_LISTING_AUTHOR;
        }
        if($pathParts[0] === 'series')
        {
            return self::TYPE_LISTING_SERIES;
        }
                        
        //a feed
        $last = array_pop($pathParts);
        $pathParts[] = $last;
        if($last === 'feed')
        {
            return self::TYPE_FEED;
        }
    
        //single URLS here are treated as pages or posts
        if(count($pathParts) === 1)
        {
            return self::TYPE_PAGE_OR_POST;
        }
    
        //two part numeric URLs are treated as date listings
        if( count($pathParts) === 2     && 
            is_numeric($pathParts[0])   &&
            is_numeric($pathParts[1]))
        {
            return self::TYPE_LISTING_DATE;
        }
    
        throw new \Exception("Unknown URL type");
    
    }
    
	public function cleanup()
	{
		if ($this->isHtml())
		{
			$responseBody = preg_replace('/<link rel=["\' ](pingback|EditURI|wlwmanifest|index|profile|prev)["\' ](.*?)>/si', '', $this->getResponseBody());
			$responseBody = preg_replace('/<meta name=["\' ]generator["\' ](.*?)>/si', '', $responseBody);
			// echo $responseBody;
			
			$this->setResponseBody($responseBody);
		}
	}
	
	public function extractAllUrls($baseUrl)
	{
		$allUrls = array();		
		if(!$this->isHtml()) 
		{ 
		    return $allUrls; 
		}
		if(!preg_match_all('/' . str_replace('/', '\/', $baseUrl) . '[^<"\'#\? ]+/i', $this->_response['body'], $matches))
		{
		    return $allUrls;
		}
		
		$allUrls = array_unique($matches[0]);
		return $allUrls;
	}	
}

class StaticHtmlOutput_Cli extends StaticHtmlOutput
{
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
		
		return [$baseUrl, $newBaseUrl, $urlsQueue];
	}
	
	protected function addNewUrlsToQueue($urlsQueue, $urlResponse, $currentUrl, $baseUrl)
	{
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
                (strpos($newUrl, '&amp;description') === false)
            );                	
	}
	
	protected function processQueue($urlsQueue, $currentUrl, $baseUrl, 
	    $archiveDir, $newBaseUrl)
	{
		$this->_exportLog = array();
		while ($currentUrl = array_shift($urlsQueue))
		{			
			echo $currentUrl, "\n";			
						
			$urlResponse = $this->instatiateUrlRequestObject($currentUrl);
			$urlResponse->cleanup();
			
			// Add current url to the list of processed urls
			$this->_exportLog[$currentUrl] = true;
			
			$urlsQueue = $this->addNewUrlsToQueue($urlsQueue, $urlResponse, $currentUrl, $baseUrl);
			
			// Save url data
			$urlResponse->replaceBaseUlr($baseUrl, $newBaseUrl);
			$this->_saveUrlData($urlResponse, $archiveDir);			
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
	
	protected function _generateArchive()
	{		
		set_time_limit(0);
		list($archiveName, $archiveDir) = $this->prepareArchiveDirectory();
		
		// Prepare queue
        list($baseUrl, $newBaseUrl, $urlsQueue) = $this->prepareQueue();
				
		// Process queue		
		$urlsQueue = $this->processQueue($urlsQueue, $currentUrl, $baseUrl, 
	         $archiveDir, $newBaseUrl);
		
		// Create archive object				
		$archiveName = $this->createArchive($archiveName, $archiveDir);
		return str_replace(ABSPATH, trailingslashit(home_url()), $archiveName);
		
	}
	
	protected function instatiateUrlRequestObject($currentUrl)
	{
    	return new StaticHtmlOutput_UrlRequest_Cli($currentUrl);
	}
}