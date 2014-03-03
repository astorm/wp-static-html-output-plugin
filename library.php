<?php
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
}