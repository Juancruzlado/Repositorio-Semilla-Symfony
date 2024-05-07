<?php
namespace Psalm\Config;

use Psalm\Config;
use SimpleXMLElement;

class ProjectFileFilter extends FileFilter
{
    /**
     * @var ProjectFileFilter|null
     */
    protected $file_filter = null;

    /**
     * @param  SimpleXMLElement $e
     * @param  Config           $config
     * @param  bool             $inclusive
     * @return static
     */
    public static function loadFromXMLElement(
        SimpleXMLElement $e,
        Config $config,
        $inclusive
    ) {
        $filter = parent::loadFromXMLElement($e, $config, $inclusive);

        if (isset($e->ignoreFiles)) {
            if (!$inclusive) {
                throw new \Psalm\Exception\ConfigException('Cannot nest ignoreFiles inside itself');
            }

            /** @var \SimpleXMLElement $e->ignoreFiles */
            $filter->file_filter = static::loadFromXMLElement($e->ignoreFiles, $config, false);
        }

        return $filter;
    }

    /**
     * @param  string  $file_name
     * @param  boolean $case_sensitive
     * @return boolean
     */
    public function allows($file_name, $case_sensitive = false)
    {
        if ($this->inclusive && $this->file_filter) {
            if (!$this->file_filter->allows($file_name, $case_sensitive)) {
                return false;
            }
        }

        return parent::allows($file_name, $case_sensitive);
    }
}
