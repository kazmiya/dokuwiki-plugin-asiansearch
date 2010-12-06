<?php
/**
 * AsianSearch Plugin for DokuWiki / action.php
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Kazutaka Miyasaka <kazmiya@gmail.com>
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) {
    die();
}

if (!defined('DOKU_PLUGIN')) {
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
}

require_once(DOKU_PLUGIN . 'action.php');

class action_plugin_asiansearch extends DokuWiki_Action_Plugin
{
    /**
     * Returns some info
     */
    function getInfo()
    {
        return confToHash(DOKU_PLUGIN . 'asiansearch/plugin.info.txt');
    }

    /**
     * Registers event handlers
     */
    function register(&$controller)
    {
        if (!function_exists('datetime_h')) {
            // DokuWiki 2009-02-14 or earlier
            $controller->register_hook(
                'SEARCH_QUERY_FULLPAGE', 'BEFORE',
                $this, 'handleQuery'
            );
        } elseif (!function_exists('valid_input_set')) {
            // DokuWiki 2009-12-25 "Lemming" (do nothing)
        } else {
            // DokuWiki 2010-11-07 "Anteater" or later
            $controller->register_hook(
                'FULLTEXT_SNIPPET_CREATE', 'BEFORE',
                $this, 'reactivateAsianSearchSnippet'
            );
        }
    }

    /**
     * Handles a search query
     */
    function handleQuery(&$event, $param)
    {
        $data =& $event->data;

        // manipulate a query
        $terms = preg_split(
            '/(".*?")/u', $data['query'], -1, PREG_SPLIT_DELIM_CAPTURE
        );

        $data['query'] = implode(
            '',
            array_map(array($this, 'manipulateTerm'), $terms)
        );
    }

    /**
     * Manipulates a search term
     */
    function manipulateTerm($str = '')
    {
        // do nothing with a "pharse"
        if (!preg_match('/^".*"$/u', $str)) {
            // fix incomplete phrase
            $str = str_replace('"', ' ', $str);

            // treat ideographic spaces (U+3000) as search term separators
            $str = preg_replace('/\x{3000}/u', ' ',  $str);

            // make phrases for asian characters
            $str = implode(
                ' ',
                array_map(array($this, 'makePhrase'), explode(' ', $str))
            );
        }

        return $str;
    }

    /**
     * Makes a "phrase" for each successive asian character
     */
    function makePhrase($str = '')
    {
        // skip if $str has a search modifier
        if (!preg_match('/^[\-\@\^]/u', $str)) {
            $str = preg_replace('/(' . IDX_ASIAN . '+)/u', ' "$1" ', $str);
            $str = trim($str);
        }

        return $str;
    }

    /**
     * Reactivates missing asian search snippets
     */
    function reactivateAsianSearchSnippet(&$event, $param)
    {
        $event->preventDefault();
        $this->revised_ft_snippet($event);
    }

    /**
     * Revised version of the ft_snippet()
     * (ft_snippet_re_preprocess is replaced)
     */
    function revised_ft_snippet(&$event)
    {
        $id = $event->data['id'];
        $text = $event->data['text'];
        $highlight = $event->data['highlight'];

        // ---> Copied from ft_snippet() - No code cleanings

        $match = array();
        $snippets = array();
        $utf8_offset = $offset = $end = 0;
        $len = utf8_strlen($text);

        // build a regexp from the phrases to highlight
        $re1 = '('.join('|',
            array_map(array($this, 'revised_ft_snippet_re_preprocess'), // <= REPLACED
            array_map('preg_quote_cb',array_filter((array) $highlight)))).')';
        $re2 = "$re1.{0,75}(?!\\1)$re1";
        $re3 = "$re1.{0,45}(?!\\1)$re1.{0,45}(?!\\1)(?!\\2)$re1";

        for ($cnt=4; $cnt--;) {
            if (0) {
            } else if (preg_match('/'.$re3.'/iu',$text,$match,PREG_OFFSET_CAPTURE,$offset)) {
            } else if (preg_match('/'.$re2.'/iu',$text,$match,PREG_OFFSET_CAPTURE,$offset)) {
            } else if (preg_match('/'.$re1.'/iu',$text,$match,PREG_OFFSET_CAPTURE,$offset)) {
            } else {
                break;
            }

            list($str,$idx) = $match[0];

            // convert $idx (a byte offset) into a utf8 character offset
            $utf8_idx = utf8_strlen(substr($text,0,$idx));
            $utf8_len = utf8_strlen($str);

            // establish context, 100 bytes surrounding the match string
            // first look to see if we can go 100 either side,
            // then drop to 50 adding any excess if the other side can't go to 50,
            $pre = min($utf8_idx-$utf8_offset,100);
            $post = min($len-$utf8_idx-$utf8_len,100);

            if ($pre>50 && $post>50) {
                $pre = $post = 50;
            } else if ($pre>50) {
                $pre = min($pre,100-$post);
            } else if ($post>50) {
                $post = min($post, 100-$pre);
            } else {
                // both are less than 50, means the context is the whole string
                // make it so and break out of this loop - there is no need for the
                // complex snippet calculations
                $snippets = array($text);
                break;
            }

            // establish context start and end points, try to append to previous
            // context if possible
            $start = $utf8_idx - $pre;
            $append = ($start < $end) ? $end : false;  // still the end of the previous context snippet
            $end = $utf8_idx + $utf8_len + $post;      // now set it to the end of this context

            if ($append) {
                $snippets[count($snippets)-1] .= utf8_substr($text,$append,$end-$append);
            } else {
                $snippets[] = utf8_substr($text,$start,$end-$start);
            }

            // set $offset for next match attempt
            //   substract strlen to avoid splitting a potential search success,
            //   this is an approximation as the search pattern may match strings
            //   of varying length and it will fail if the context snippet
            //   boundary breaks a matching string longer than the current match
            $utf8_offset = $utf8_idx + $post;
            $offset = $idx + strlen(utf8_substr($text,$utf8_idx,$post));
            $offset = utf8_correctIdx($text,$offset);
        }

        $m = "\1";
        $snippets = preg_replace('/'.$re1.'/iu',$m.'$1'.$m,$snippets);
        $snippet = preg_replace('/'.$m.'([^'.$m.']*?)'.$m.'/iu','<strong class="search_hit">$1</strong>',hsc(join('... ',$snippets)));

        // <--- Copied from ft_snippet() - No code cleanings

        $event->data['snippet'] = $snippet;
    }

    /**
     * Revised version of the ft_snippet_re_preprocess()
     */
    function revised_ft_snippet_re_preprocess($term)
    {
        if (preg_match('/' . IDX_ASIAN . '/u', $term)) {
            return $term;
        } else {
            return ft_snippet_re_preprocess($term);
        }
    }
}
