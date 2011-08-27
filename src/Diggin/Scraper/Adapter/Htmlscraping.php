<?php
/**
 * This class is remodeling of HTMLScraping
 * 
 * @see http://www.rcdtokyo.com/etc/htmlscraping/
 *
 * This class require 
 *  tidy-extension(recommended) or
 *  HTMLParser class(http://www.rcdtokyo.com/ucb/contents/i000799.php) 
 */

/**
 * ---------------------------------------------------------------------
 * HTMLScraping class
 * ---------------------------------------------------------------------
 * LICENSE: This source file is subject to the GNU Lesser General Public
 * License as published by the Free Software Foundation;
 * either version 2.1 of the License, or any later version
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/licenses/lgpl.html
 * If you did not have a copy of the GNU Lesser General Public License
 * and are unable to obtain it through the web, please write to
 * the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
 * ---------------------------------------------------------------------
 */
namespace Diggin\Scraper\Adapter;

use Diggin\Http\Charset\Front\UrlRegex,
    Diggin\Http\Charset\Front\DocumentConverter,
    Diggin\Scraper\Adapter\SimplexmlAbstract,
    Diggin\Scraper\Adapter\Wrapper\SimpleXMLElement as SimpleXMLElementWrapper,
    Diggin\Scraper\Adapter\Exception;

class Htmlscraping extends SimplexmlAbstract
{
    /**
     * Configuration array, set using the constructor or using ::setConfig()
     *
     * @var array
     * @see http://tidy.sourceforge.net/docs/quickref.html
     */
    protected $config = array(
                'tidy' => array('output-xhtml' => true, 
                                'wrap' => 0,
                                /*'wrap-script-literals' => true*/),
                'pre_ampersand_escape' => false,
                'url' => null
              );

    /**
     * @var Diggin_Http_Response_Charset_Front_EncodeInterface
     */
    private $_charsetFront;

    /**
     * @var array
     */
    private $backup = array();

    /**
     * @var integer
     */
    private $backup_count = 0;

    private $_xhtmltransitionalDtd;
    
    /**
     * Casts a SimpleXMLElement
     *
     * @param Zend_Http_Response $response
     * @return SimpleXMLElement
     */
    public function getSimplexml($response)
    {
        try {
            $this->setConfig(array('pre_ampersand_escape' => true));
            $xhtml = $this->getXhtml($response);
        } catch (Exception $dsae) {
            throw $dsae;
        } catch (\Exception $e) {
            throw new Exception\RuntimeException('Unexpected Exception Occured', 0, $e);
        }
        
        /*
         * Remove default namespace.
         * This is because that SimpleXMLElement->registerXPathNamespace() may cause
         * a problem under some circumstances (confirmed with PHP 5.1.6 so far).
         * So you do not need to use SimpleXMLElement->registerXPathNamespace()
         * when you use SimpleXMLElement->xpath().
         */
        //origin is
        //$responseBody = preg_replace('/\sxmlns="[^"]+"/', '', $xhtml);
        
        $responseBody = preg_replace(array('/\sxmlns:?[A-Za-z]*="[^"]+"/', "/\sxmlns:?[A-Za-z]*='[^']+'/"), '', $xhtml);

        try {
            //@see http://php.net/libxml.constants
            if (isset($this->config['libxmloptions'])) {
                $xml_object = @new SimpleXMLElementWrapper($responseBody, $this->config['libxmloptions']);
            } else {
                $xml_object = @new SimpleXMLElementWrapper($responseBody);
            }
        } catch (Exception $dsae) {
            throw $dsae;
        } catch (\Exception $e) {
            throw new Exception\RuntimeException('Unexpected Exception Occured', 0, $e);
        }

        return $xml_object;
    }

    /**
     * Return array contains formated XHTML string
     * created from the responded HTML of the given URL.
     * array[code] => HTTP status code
     * array[headers] => HTTP headers
     * array[headers] => formated XHTML string made from the entity body
     * Throw exception if error.
     *
     * @param  string  $url
     * @param  string $responseBody
     * @return string 
     * @throws Diggin_Scraper_Adapter_Exception
     */
    final public function getXhtml($response)
    {
        $contenttype = $response->headers()->get('content-type')->getFieldValue();

        // convert to UTF-8
        $document = array(
            'url' => $this->config['url'], 
            'content' => array('body' => $response->getBody(), 'content-type' => $contenttype)
         );
        list($responseBody, $this->backup) = $this->getCharsetFront()->convert($document, $this->backup);

        /*
         * Initialize the backups.
         */
        $this->backup = array();
        $this->backup_count = 0;
        /*
         * Removing SCRIPT and STYLE is recommended.
         * The following substitute code will capsulate the content of the tags in CDATA.
         * If use it, be sure that some JavaScript method such as document.write
         * is not compliant with XHTML/XML.
         */
        $tags = array('script', 'style');
        foreach ($tags as $tag) {
            $responseBody = preg_replace("/<$tag\b[^>]*?>.*?<\/$tag\b[^>]*?>/si", '' , $responseBody);
        }
        /*
         * Backup CDATA sections for later process.
         */
        $responseBody = preg_replace_callback(
            '/<!\[CDATA\[.*?\]\]>/s', array($this, 'backup'), $responseBody
        );
        /*
         * Comment section must not contain two or more adjacent hyphens.
         */
        $responseBody = preg_replace_callback(
            '/<!--(.*?)-->/si',
            create_function('$matches', '
                return "<!-- ".preg_replace("/-{2,}/", "-", $matches[1])." -->";
            '),
            $responseBody
        );
        /*
         * Backup comment sections for later process.
         */
        $responseBody = preg_replace_callback(
            '/<!--.*?-->/s', array($this, 'backup'), $responseBody
        );
        /*
         * Process tags that is potentially dangerous for XML parsers.
         */
        $responseBody = preg_replace_callback(
            '/(<textarea\b[^>]*?>)(.*?)(<\/textarea\b[^>]*?>)/si',
            create_function('$matches', '
                return $matches[1].str_replace("<", "&lt;", $matches[2]).$matches[3];
            '),
            $responseBody
        );
        $responseBody = preg_replace_callback(
            '/<xmp\b[^>]*?>(.*?)<\/xmp\b[^>]*?>/si',
            create_function('$matches', '
                return "<pre>".str_replace("<", "&lt;", $matches[1])."</pre>";
            '),
            $responseBody
        );
        $responseBody = preg_replace_callback(
            '/<plaintext\b[^>]*?>(.*)$/si',
            create_function('$matches', '
                return "<pre>".str_replace("<", "&lt;", $matches[1])."</pre>";
            '),
            $responseBody
        );
        /*
         * Remove DTD declarations, wrongly placed comments etc.
         * This must be done before removing DOCTYPE.
         */
        $responseBody = preg_replace('/<!(?!DOCTYPE)[^>]*?>/si', '', $responseBody);
        /*
         * XML and DOCTYPE declaration will be replaced.
         */
        $responseBody = preg_replace('/<!DOCTYPE\b[^>]*?>/si', '', $responseBody);
        $responseBody = preg_replace('/<\?xml\b[^>]*?\?>/si', '', $responseBody);
        if (preg_match('/^\s*$/s', $responseBody)) {
            throw new Exception\RuntimeException('The entity body became empty after preprocessing.');
        }

        /*
         * Restore CDATAs and comments.
         */
        for ($i = 0; $i < $this->backup_count; $i++) {
            $responseBody = str_replace("<restore count=\"$i\" />", $this->backup[$i], $responseBody);
        }

        /*
         * Use Tidy to format HTML if available.
         * Otherwise, use HTMLParser class (is slower and consumes much memory).
         */
        
        /*
         * Replace every '&' with '&amp;'
         * for XML parser not to break on non-predefined entities.
         * So you may need to replace '&amp;' with '&'
         * to have the original HTML string from returned SimpleXML object.
         * 
         * //@see 
         * And tidy, it will replace htmlspecialchars('>' '<') to ('&lt;, '&gt;'') 
         * if not as Html Tag for tidy.
         * so, "str_replace('&')" before tidy.
         */
        
        if (extension_loaded('tidy')) {
            if ($this->config['pre_ampersand_escape']) {
                $responseBody = str_replace('&', '&amp;', $responseBody);
            }
            $tidy = new \tidy();
            $tidy->parseString($responseBody, $this->config['tidy'], 'UTF8');
            $tidy->cleanRepair();
            $responseBody = $tidy->html();
        } else {
            if ($this->config['pre_ampersand_escape']) {
                $responseBody = str_replace('&', '&amp;', $responseBody);
            }

            // use autoload if available
            if (!class_exists('HTMLParser')) {
                throw new Exception\HtmlscrapingEnvironmentException('require tidy or HTMLParser class');
            }
            $parser = new HTMLParser;
            $parser->setRule($this->loadXhtmlTransitionalDtd());
            $parser->setRoot('html', array('xmlns' => 'http://www.w3.org/1999/xhtml'));
            $parser->setGenericParent('body');
            $parser->parse($responseBody);
            $responseBody = $parser->dump();
        }
        /*
         * Valid XHTML DOCTYPE declaration (with DTD URI) is required
         * for SimpleXMLElement->asXML() method to produce proper XHTML tags.
         */
        $declarations = '<?xml version="1.0" encoding="UTF-8"?>';
        $declarations .= '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" ';
        $declarations .= '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        
        return "$declarations$responseBody";
    }
    
    /**
     * lazy load xhtml Transitional
     * 
     * avoid memory leak
     * @see http://amaxi.sitemix.jp/blog/archives/205
     */
    public function loadXhtmlTransitionalDtd()
    {
        if (!$this->_xhtmltransitionalDtd) {
            $this->_xhtmltransitionalDtd = require 'xhtml1-transitional_dtd.inc.php';
        }
        
        return $this->_xhtmltransitionalDtd;
    }

    /**
     * backup (Html and Xml comment)
     * 
     * @param  array   $matches
     * @return string
     */
    private function backup($matches)
    {
        $this->backup[] = $matches[0];
        $replace = "<restore count=\"{$this->backup_count}\" />";
        $this->backup_count++;
        
        return $replace;
    }
    

    /**
     * Set configuration parameters for this
     *
     * @param array $config
     * @return Diggin_Scraper_Adapter_Htmlscraping
     * @throws Diggin_Scraper_Adapter_Exception
     */
    public function setConfig($config = array())
    {
        if (!is_array($config)) {
            throw new Exception\InvalidArgumentException('Expected array parameter, given ' . gettype($config));
        }
        
        if (isset($config['tidy']['output-xhtml']) && $config['tidy']['output-xhtml'] !== true) {
            throw new Exception\InvalidArgumentException('tidy-config "output-xhtml" not as true - not allowed');
        }
        
        foreach ($config as $k => $v) {
            $this->config[strtolower($k)] = $v;
        }
        
        return $this;
    }

    public function setCharsetFront(DocumentConverter $charseFront)
    {
        $this->_charsetFront = $charsetFront;
    }

    public function getCharsetFront()
    {
        if (!$this->_charsetFront) {
            $this->_charsetFront = new UrlRegex;
        }

        return $this->_charsetFront;
    }

}
