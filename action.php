<?php
/**
 * DokuWiki Plugin Asian Search
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Kazutaka Miyasaka <kazmiya@gmail.com>
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'action.php');

class action_plugin_asiansearch extends DokuWiki_Action_Plugin {
    /**
     * return some info
     */
    function getInfo() {
        return array(
            'author' => 'Kazutaka Miyasaka',
            'email'  => 'kazmiya@gmail.com',
            'date'   => '2009-09-03',
            'name'   => 'Asian Search Plugin',
            'desc'   => 'Manipulates a search query for a better search experience in Asian languages',
            'url'    => 'http://www.dokuwiki.org/plugin:asiansearch'
        );
    }

    /**
     * register the event handlers
     */
    function register(&$controller) {
        $controller->register_hook('SEARCH_QUERY_FULLPAGE', 'BEFORE', $this, 'handleQuery', array());
    }

    /**
     * handle a search query
     */
    function handleQuery(&$event, $param) {
        $data =& $event->data;

        var_dump(ft_queryParser($data['query']));

        // manipulate a query
        $terms = preg_split('/(".*?")/u', $data['query'], -1, PREG_SPLIT_DELIM_CAPTURE);
        $data['query'] = implode('', array_map(array($this, '_manipulateTerm'), $terms));

        var_dump(ft_queryParser($data['query']));
        //msg(var_dump(_ft_pageSearch($data)));
    }

    /**
     * manipulate a search term
     */
    function _manipulateTerm($str = '') {
        // do nothing with a "pharse"
        if (! preg_match('/^".*"$/u', $str)) {
            // fix incomplete phrase
            $str = str_replace('"', ' ', $str);

            // treat ideographic spaces (U+3000) as search term separators
            $str = preg_replace('/\x{3000}/u', ' ',  $str);

            // tokenize a term by means of morphological analyzer
            $str = $this->_tokenize($str);

            // make phrases for asian characters
            $str = implode(' ', array_map(array($this, '_makePhrase'), explode(' ', $str)));
        }
        return $str;
    }

    /**
     * make a "phrase" for each successive asian character
     */
    function _makePhrase($str = '') {
        // skip if $str has a search modifier
        if (! preg_match('/^[\-\@\^]/u', $str)) {
            $str = preg_replace('/('.IDX_ASIAN.'+)/u', ' "$1" ', $str);
            //$str = preg_replace('/ {2,}/', ' ', $str);
            $str = trim($str);
        }
        return $str;
    }

    function _tokenize($str = '') {
        // thanks to Osamu Higuchi: DokuWikiの日本語対応 (Japanese)
        // http://www.higuchi.com/dokuwiki/dokuwiki:localize

        if (! function_exists(proc_open)) { return $str; }

        $cmd_tokenize = '/usr/bin/mecab -O wakati';
        $dspec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('file', '/dev/null', 'w')
        );
        $process = proc_open($cmd_tokenize, $dspec, $pipes);

        if (! is_resource($process)) { return $str; }

        stream_set_blocking($pipes[0], FALSE);
        stream_set_blocking($pipes[1], FALSE);
        fwrite($pipes[0], $str."\n");
        fclose($pipes[0]);

        $str_processed = '';
        while (! feof($pipes[1])) {
            $str_processed .= fgets($pipes[1], 32768);
        }
        fclose($pipes[1]);
        proc_close($process);

        return $str_processed;
    }
}
