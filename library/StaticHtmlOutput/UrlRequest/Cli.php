<?php
class StaticHtmlOutput_UrlRequest_Cli extends StaticHtmlOutput_UrlRequest
{
    const TYPE_STATIC_WP_CONTENT    = 'static-wp-content';
    const TYPE_STATIC_WP_INCLUDES   = 'static-wp-includes';
    
    const TYPE_HOMEPAGE             = 'homepage';
    const TYPE_FEED                 = 'feed';
    const TYPE_PAGE_OR_POST         = 'page-or-post';
    
    const TYPE_LISTING_TUMBLR_PAGE  = 'listing-tumblr-page';
    const TYPE_LISTING_TAG          = 'listing-tag';
    const TYPE_LISTING_CATEGORY     = 'listing-category';
    const TYPE_LISTING_AUTHOR       = 'listing-author';
    const TYPE_LISTING_SERIES       = 'listing-series';
    const TYPE_LISTING_DATE         = 'listing-date';  
    
    const TYPE_WP_JSON              = 'wp-json';    
    
    public $oembedUrls              = [];
    
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
        $requestType = $this->getUrlType($url);
        return in_array($requestType, 
            [   self::TYPE_HOMEPAGE,self::TYPE_FEED,self::TYPE_PAGE_OR_POST,
                self::TYPE_LISTING_CATEGORY,self::TYPE_LISTING_AUTHOR,
                self::TYPE_LISTING_SERIES,self::TYPE_LISTING_DATE,
                self::TYPE_WP_JSON,self::TYPE_LISTING_TAG, 
                self::TYPE_LISTING_TUMBLR_PAGE]);
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
        '.xml'=>'text/rss',
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

        if($pathParts[0] === 'tag')
        {
            return self::TYPE_LISTING_TAG;
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
        if($last === 'feed' || $last === 'feed.xml' || $last === 'atom')
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
    
        if( count($pathParts) === 4     &&
            is_numeric($pathParts[0])   &&
            is_numeric($pathParts[1])   &&
            is_numeric($pathParts[3]))
        {
            return self::TYPE_LISTING_TUMBLR_PAGE;
        }
        var_dump($pathParts);
        throw new \Exception("Unknown URL type");
    
    }
    
	public function cleanup()
	{
		if ($this->isHtml())
		{
		    //strip stuff we don't want
			$responseBody = preg_replace('/<link rel=["\' ](pingback|EditURI|wlwmanifest|index|profile|prev)["\' ](.*?)>/si', '', $this->getResponseBody());
			$responseBody = preg_replace('/<meta name=["\' ]generator["\' ](.*?)>/si', '', $responseBody);
			// echo $responseBody;
		
		    //fix RSS feeds
		    $responseBody = preg_replace('%
                (<link[ ]rel=["\' ]alternate["\' ]
                [ ] 
                type=["\' ]application/rss\+xml["\' ]
                .*?
                /feed)
                (/?)
                (.*?>)
            %six','\1/feed.xml\3',$responseBody);
            
            //remove weird prefetch things
            $responseBody = str_replace("<link rel='dns-prefetch' href='//2016.alanstorm.dev' />", '', $responseBody);
            $responseBody = str_replace("<link rel='dns-prefetch' href='//s.w.org' />", '', $responseBody);

            //remove short links
            $responseBody = preg_replace('%
                <link 
                [ ]
                rel=.shortlink.[^>]+?
                >
            %six', '', $responseBody);
            
            //look for oembed URLs, make note of them            
            $oembeds = $this->getOembedsFromContent($responseBody);
            foreach($oembeds as $url)
            {                
                $key = $this->getNewOembedUrlFromCurrentUrl($url);
                $responseBody = str_replace($url . '"', $key . '"', $responseBody);
                $this->oembedUrls[$key] = $url;                
            } 

			$this->setResponseBody($responseBody);
		}
	}
	
	protected function getNewOembedUrlFromCurrentUrl($url)
	{
	    $parts          = explode('?', $url);
	    $justUrl        = array_shift($parts);
	    $queryString    = implode('/', $parts);
	    $queryString    = urldecode($queryString); 
	    
	    $ext            = 'json';
	    if(strpos($url, 'format=xml') !== false)
	    {
	        $ext        = 'xml';
	    }
	    return $justUrl . '/' . preg_replace('%[^a-z0-9]%six','_',$queryString) . 
	        '.' . $ext;
	}
	
	protected function getOembedsFromContent($responseBody)
	{
        $matches = [];            
        preg_match_all('%
            <link
            [^>]+?
            type=["\' ]
            (?:application/json\+oembed|text/xml\+oembed)
            ["\' ]
            [^>]+?
            href=["\' ](.+?)["\' ]
            %six', $responseBody, $matches, PREG_SET_ORDER);
            
        $urls = [];            
        foreach($matches as $match)
        {
            $urls[] = $match[1];
        }             
        return $urls;	
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