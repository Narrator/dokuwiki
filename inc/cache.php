<?php
/**
 * Generic class to handle caching
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Chris Smith <chris@jalakai.co.uk>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../').'/');

require_once(DOKU_INC.'inc/io.php');
require_once(DOKU_INC.'inc/pageutils.php');
require_once(DOKU_INC.'inc/parserutils.php');

class cache {
  var $key = '';        // primary identifier for this item
  var $ext = '';        // file ext for cache data, secondary identifier for this item
  var $cache = '';      // cache file name

  var $_event = '';      // event to be triggered during useCache

  function cache($key,$ext) {
    $this->key = $key;
    $this->ext = $ext;
    $this->cache = getCacheName($key,$ext);
  }

  /**
   * public method to determine whether the cache can be used
   *
   * to assist in cetralisation of event triggering and calculation of cache statistics, 
   * don't override this function override _useCache()
   *
   * @param  array   $depends   array of cache dependencies, support dependecies:
   *                            'age'   => max age of the cache in seconds
   *                            'files' => cache must be younger than mtime of each file
   *
   * @return bool    true if cache can be used, false otherwise
   */
  function useCache($depends=array()) {
    $this->depends = $depends;

    if ($this->_event) {
      return $this->_stats(trigger_event($this->_event,$this,array($this,'_useCache')));
    } else {
      return $this->_stats($this->_useCache());
    }
  }

  /*
   * private method containing cache use decision logic
   *
   * this function can be overridden
   *
   * @return bool               see useCache()
   */
  function _useCache() {

    if (isset($_REQUEST['purge'])) return false;                    // purge requested?
    if (!($this->_time = @filemtime($this->cache))) return false;   // cache exists?

    // cache too old?
    if (!empty($this->depends['age']) && ((time() - $this->_time) > $this->depends['age'])) return false;

    if (!empty($this->depends['files'])) {
      foreach ($this->depends['files'] as $file) {
        if ($this->_time < @filemtime($file)) return false;         // cache older than files it depends on?
      }
    }

    return true;
  }

  /**
   * retrieve the cached data
   *
   * @param   bool   $clean   true to clean line endings, false to leave line endings alone
   * @return  string          cache contents
   */
  function retrieveCache($clean=true) {
    return io_readFile($this->cache, $clean);
  }

  /**
   * cache $data
   *
   * @param   string $data   the data to be cached
   * @return  none
   */
  function storeCache($data) {
    io_savefile($this->cache, $data);
  }

  /**
   * remove any cached data associated with this cache instance
   */
  function removeCache() {
    @unlink($this->cache);
  }

  /**
   * record cache hits statistics
   *
   * @param    bool   $success   result of this cache use attempt
   * @return   bool              pass-thru $success value
   */
  function _stats($success) {
    global $conf;
    static $stats = NULL;
    static $file;

    if (is_null($stats)) {
      $file = $conf['cachedir'].'/cache_stats.txt';
      $lines = explode("\n",io_readFile($file));

      foreach ($lines as $line) {
        $i = strpos($line,',');
	$stats[substr($line,0,$i)] = $line;
      }
    }

    if (isset($stats[$this->ext])) {
      list($ext,$count,$successes) = explode(',',$stats[$this->ext]);
    } else {
      $ext = $this->ext;
      $count = 0;
      $successes = 0;
    }

    $count++;
    $successes += $success ? 1 : 0;
    $stats[$this->ext] = "$ext,$count,$successes";

    io_saveFile($file,join("\n",$stats));

    return $success;
  }
}

class cache_parser extends cache {

  var $file = '';       // source file for cache
  var $mode = '';       // input mode (represents the processing the input file will undergo)

  var $_event = 'PARSER_CACHE_USE';

  function cache_parser($id, $file, $mode) {
    if ($id) $this->page = $id;
    $this->file = $file;
    $this->mode = $mode;

    parent::cache($file.$_SERVER['HTTP_HOST'].$_SERVER['SERVER_PORT'],'.'.$mode);
  }

  function _useCache() {
    global $conf;

    if (!@file_exists($this->file)) return false;                   // source exists?

    if (!isset($this->depends['age'])) $this->depends['age'] = $conf['cachetime'];

    // parser cache file dependencies ...
    $files = array($this->file,                                     // ... source
                   DOKU_CONF.'dokuwiki.php',                        // ... config
                   DOKU_CONF.'local.php',                           // ... local config
                   DOKU_INC.'inc/parser/parser.php',                // ... parser
                   DOKU_INC.'inc/parser/handler.php',               // ... handler
             );

    $this->depends['files'] = !empty($this->depends['files']) ? array_merge($files, $this->depends['files']) : $files;
    return parent::_useCache($depends);
  }

}

class cache_renderer extends cache_parser {

  function _useCache() {
    global $conf;

    // renderer cache file dependencies ...
    $files = array(
#                   $conf['cachedir'].'/purgefile',                 // ... purgefile - time of last add
                   DOKU_INC.'inc/parser/'.$this->mode.'.php',      // ... the renderer
             );

    if (isset($this->page)) { $files[] = metaFN($this->page,'.meta'); }

    $this->depends['files'] = !empty($this->depends['files']) ? array_merge($files, $this->depends['files']) : $files;
    if (!parent::_useCache($depends)) return false;

    // for wiki pages, check for internal link status changes
    if (isset($this->page)) {
      $links = p_get_metadata($this->page,"relation references");

      if (!empty($links)) {
        foreach ($links as $id => $exists) {
          if ($exists != @file_exists(wikiFN($id,'',false))) return false;
	}
      }
    }

    return true;
  }
}

class cache_instructions extends cache_parser {

  function cache_instructions($id, $file) {
    parent::cache_parser($id, $file, 'i');
  }

  function retrieveCache() {
    $contents = io_readFile($this->cache, false);
    return !empty($contents) ? unserialize($contents) : array();
  }

  function storeCache($instructions) {
    io_savefile($this->cache,serialize($instructions));
  }
}