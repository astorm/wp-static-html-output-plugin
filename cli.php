<?php


/**
 * Manages the WP Super Cache plugin
 */
class WPStaticHtmlOutput_Command extends WP_CLI_Command {
    protected function _init()
    {
        include dirname(__FILE__) . '/library.php';
    }
    
    /**
     * Initiates an Export.
     * 
     * ## OPTIONS
     * 
     * <base_url>
     * : The base URL of the newly generated static site
     * 
     * ## EXAMPLES
     * 
     *     wp static-html-output generate http://example.com 
     *
     * @synopsis <base_url>
     */
    function generate( $args, $assoc_args ) {
        $this->_init();
        list( $new_url ) = $args;
        $plugin = StaticHtmlOutput_Cli::resetInstance();
        
        $plugin->getOptionsOverride()
			->setOption('baseUrl', $new_url)
			->setOption('additionalUrls', '')
			->setOption('generateZip', '1')
			->setOption('retainStaticFiles', '1')
			->setOption('sendViaFTP', '')
			->setOption('ftpServer', '')
			->setOption('ftpUsername', '')
			->setOption('ftpRemotePath', '')		
			->save();
			
        $results = $plugin->generateArchiveOverride();
        $parts   = parse_url($results);
        $path    = array_key_exists('path', $parts) ? $parts['path'] : false;

        // Print a success message
        WP_CLI::success($results);        
        if($path)
        {
            $path = explode('.zip',$path);
            $path = array_shift($path);
            WP_CLI::success($path);                
        }        
        WP_CLI::success( "Done" );
    }
}

WP_CLI::add_command( 'static-html-output', 'WPStaticHtmlOutput_Command' );

