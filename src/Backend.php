<?php
namespace NSWDPC\AsyncLoader;

use SilverStripe\View\HTML;
use SilverStripe\Control\Controller;
use SilverStripe\View\Requirements;
use Silverstripe\View\Requirements_Backend;
use Silverstripe\Core\Convert;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use Exception;
use DOMDocument;
use SilverStripe\Admin\LeftAndMain;


/**
 * Async Requirements backend using loadjs
 * @author James
 */
class Backend extends Requirements_Backend
{
    use Configurable;

    const LOADER_URL = "https://cdnjs.cloudflare.com/ajax/libs/loadjs/4.2.0/loadjs.min.js";
    const LOADER_SRI_HASH = "sha512-kA5njTcOKIwpz6cEPl//I31UH3ivohgL+WSVjdO/iMQWbuzHqxuAdPjRvLEHXTa+M/4AtZNMI6aOEvBtOof7Iw==";
    // content of the above URL
    const LOADER_SCRIPT = <<<LOADJS
loadjs=function(){var h=function(){},c={},u={},f={};function o(e,n){if(e){var r=f[e];if(u[e]=n,r)for(;r.length;)r[0](e,n),r.splice(0,1)}}function l(e,n){e.call&&(e={success:e}),n.length?(e.error||h)(n):(e.success||h)(e)}function d(r,t,s,i){var c,o,e=document,n=s.async,u=(s.numRetries||0)+1,f=s.before||h,l=r.replace(/[\?|#].*$/,""),a=r.replace(/^(css|img)!/,"");i=i||0,/(^css!|\.css$)/.test(l)?((o=e.createElement("link")).rel="stylesheet",o.href=a,(c="hideFocus"in o)&&o.relList&&(c=0,o.rel="preload",o.as="style")):/(^img!|\.(png|gif|jpg|svg|webp)$)/.test(l)?(o=e.createElement("img")).src=a:((o=e.createElement("script")).src=r,o.async=void 0===n||n),!(o.onload=o.onerror=o.onbeforeload=function(e){var n=e.type[0];if(c)try{o.sheet.cssText.length||(n="e")}catch(e){18!=e.code&&(n="e")}if("e"==n){if((i+=1)<u)return d(r,t,s,i)}else if("preload"==o.rel&&"style"==o.as)return o.rel="stylesheet";t(r,n,e.defaultPrevented)})!==f(r,o)&&e.head.appendChild(o)}function r(e,n,r){var t,s;if(n&&n.trim&&(t=n),s=(t?r:n)||{},t){if(t in c)throw"LoadJS";c[t]=!0}function i(n,r){!function(e,t,n){var r,s,i=(e=e.push?e:[e]).length,c=i,o=[];for(r=function(e,n,r){if("e"==n&&o.push(e),"b"==n){if(!r)return;o.push(e)}--i||t(o)},s=0;s<c;s++)d(e[s],r,n)}(e,function(e){l(s,e),n&&l({success:n,error:r},e),o(t,e)},s)}if(s.returnPromise)return new Promise(i);i()}return r.ready=function(e,n){return function(e,r){e=e.push?e:[e];var n,t,s,i=[],c=e.length,o=c;for(n=function(e,n){n.length&&i.push(e),--o||r(i)};c--;)t=e[c],(s=u[t])?n(t,s):(f[t]=f[t]||[]).push(n)}(e,function(e){l(n,e)}),r},r.done=function(e){o(e,[])},r.reset=function(){c={},u={},f={}},r.isDefined=function(e){return e in c},r}();
LOADJS;

    const LOADER_SCRIPT_PLACEHOLDER = "<!-- asyncloader_script_requirements_placeholder -->";// put this comment where you want scripts to load

    protected $bundle_name = 'requirements_bundle';
    protected $bundle_name_css = 'requirements_bundle_css';

    protected $bundle_scripts;
    protected $bundle_stylesheets = [];

    private static $algo = "sha512";
    private static $use_in_leftandmain = false;

    /**
     * Stores a JS bundle linked to $bundle_name
     * @param string $bundle_name the name of the bundle
     * @param array $scripts the scripts in the bundle
     * @param string  $success javascript used in the success callback
     */
    public function bundle($bundle_name, array $scripts, $success = '')
    {
        if (!$bundle_name) {
            $bundle_name = "bundle_" . md5(implode(",", $scripts));
        }
        $bundle_scripts = [];
        foreach($scripts as $k => $script) {
            if(is_int($k)) {
                // deprecated: make key the path
                $bundle_scripts[$script] = [];
            } else {
                // key is the path, able to provide options
                $bundle_scripts[$k] = $script;
            }
        }
        $this->bundle_scripts[$bundle_name] = [
            'scripts' => $bundle_scripts,
            'success' => $success,
            'error' => ''
        ];
    }

    /**
     * Stores a CSS bundle linked to $bundle_name
     */
    public function bundle_css($bundle_name, array $stylesheets, $success = '')
    {
        if (!$bundle_name) {
            $bundle_name = "bundle_" . md5(implode(",", $stylesheets));
        }
        $this->bundle_stylesheets[$bundle_name] = [ 'stylesheets' => $stylesheets, 'success' => $success ];
    }

    /**
     * Returns some plain ol' Javascript that will dispatch an event when the named bundle has loaded
     * IE does not support CustomEvent correctly
     */
    private function bundleDispatch($bundle, $error = false)
    {
        $event_name = "load_{$bundle}" . ($error ? "_error" : "");
        $dispatcher = <<<JAVASCRIPT
if ( typeof window.CustomEvent === "function" ) {
    var evt = new CustomEvent('$event_name');
} else {
    var evt = document.createEvent('CustomEvent');
    evt.initCustomEvent('$event_name', true, true, null);
}
document.dispatchEvent(evt);
JAVASCRIPT;
        return $dispatcher;
    }

    /**
     * Return loadjs itself
     */
    private function asyncLoader()
    {
        $script = self::LOADER_SCRIPT;
        return $script;
    }

    /**
     * Load the scripts requested via Requirements::javascript, in the bundle 'requirements_bundle'
     * @param array $scripts keys are the script paths, values are script options
     * @note to avoid massive refactor, these scripts are loaded in the order of requirement with async set to false - loadjs will load them in parallel but executee them in series
     */
    private function asyncScriptLoader(array $scripts)
    {

        $loader_scripts = "";
        // Hit up custom scripts after the requirements_bundle has loaded
        $loader_scripts .= "var loadjs_ready_{$this->bundle_name} = function() {\n";
        // dispatch the event when the bundle_name has loaded successfully
        $loader_scripts .= $this->bundleDispatch($this->bundle_name) . "\n";
        if (!empty($this->customScript)) {
            foreach (array_diff_key($this->customScript, $this->blocked) as $script) {
                $loader_scripts .= "{$script}\n";
            }
        }
        $loader_scripts .= "};\n";

        if (empty($scripts)) {
            // No scripts, notify custom scripts
            $loader_scripts .= "loadjs_ready_{$this->bundle_name}();\n";
        } else {
            $success= "loadjs_ready_{$this->bundle_name}();";
            $error = "console.log('Not found:', nf);";
            $loader_scripts .= $this->loadJsBundle($scripts, $this->bundle_name, $success, $error);
        }

        return $loader_scripts;
    }

    /**
     * Include any specific script bundles of scripts
     * You can create a bundle like such:
     *     $backend = Requirements::backend();
     *     $backend->bundle(
     *      'threejs',
     *      [
     *          'https://cdnjs.cloudflare.com/ajax/libs/three.js/r120/three.min.js' => [
     *              'integrity' => 'sha512-kgjZw3xjgSUDy9lTU085y+UCVPz3lhxAtdOVkcO4O2dKl2VSBcNsQ9uMg/sXIM4SoOmCiYfyFO/n1/3GSXZtSg==',
     *              'crossorigin' => 'anonymous'
     *          ]
     *      ]
     *     );
     *
     */
    private function addBundleScripts()
    {
        if (empty($this->bundle_scripts)) {
            return "";
        }
        // load all bundle scripts
        $scripts = "";
        foreach ($this->bundle_scripts as $bundle_name => $bundle) {
            if ($bundle_name == $this->bundle_name) {
                throw new Exception("You cannot name a bundle '{$this->bundle_name}'");
            }
            $success = isset($bundle['success']) ? $bundle['success'] : '';
            $error = isset($bundle['error']) ? $bundle['error'] : '';
            $scripts .= $this->loadJsBundle($bundle['scripts'], $bundle_name, $success, $error);
        }
        return $scripts;
    }

    /**
     * Loads a bundle of scripts using loadjs
     */
    protected function loadJsBundle(array $scripts, $bundle_name, $success = "", $error = "") {
        $script_paths = array_keys($scripts);
        $encoded_scripts = json_encode($scripts, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        $encoded_script_paths = json_encode($script_paths, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        $loader = "<!-- bundle start -->\n";
        $loader .= "var loadjs_script_{$bundle_name} = {$encoded_scripts};\n";
        $loader .= "loadjs( {$encoded_script_paths} , '{$bundle_name}', {\n";
        $loader .= "async: false,\n";
        $loader .= <<<JS
before: function(path, el) {
    if(loadjs_script_{$bundle_name}[path].integrity) {
        el.integrity = loadjs_script_{$bundle_name}[path].integrity;
    }
    if(loadjs_script_{$bundle_name}[path].crossorigin) {
        el.crossOrigin = loadjs_script_{$bundle_name}[path].crossorigin;
    }
},
JS;

        if($success) {
            $loader .= "success: function() { {$success} },\n";
        }
        if($error) {
            $loader .= "error: function(nf) { {$error} }\n";
        }
        $loader .= "});\n";
        $loader .= "<!-- bundle end -->\n";

        return $loader;
    }

    /**
     * Include any specific stylesheet bundles that are found
     */
    private function addBundleStylesheets()
    {
        if (empty($this->bundle_stylesheets)) {
            return "";
        }
        $stylesheets = "";
        foreach ($this->bundle_stylesheets as $bundle_name => $bundle) {
            if ($bundle_name == $this->bundle_name_css) {
                throw new Exception("You cannot name a bundle '{$this->bundle_name_css}'");
            }
            $success = isset($bundle['success']) ? $bundle['success'] : '';
            $name = json_encode($bundle_name);
            $stylesheets .= "loadjs(\n";
            $stylesheets .= json_encode($bundle['stylesheets'], JSON_UNESCAPED_SLASHES);
            $stylesheets .= ", " . $name;
            $stylesheets .= ", {\n";
            $stylesheets .= "success: function() {},\n";
            $stylesheets .= "error: function(nf) {}\n";
            $stylesheets .= "});\n";
        }
        return $stylesheets;
    }

    /**
     * Update the given HTML content with the appropriate include tags for the registered
     * requirements. Needs to receive a valid HTML/XHTML template in the $html parameter,
     * including a head and body tag.
     *
     * @param string $html HTML content that has already been parsed from the $templateFile
     *                             through {@link SSViewer}
     * @return string HTML content augmented with the requirements tags
     */
    public function includeInHTML($html)
    {

        /*
        if(!$this->config()->get('use_in_leftandmain')) {
            try {
                $controller = Controller::curr();
                if($controller && $controller instanceof LeftAndMain) {
                    return parent::includeInHTML($html);
                }
            } catch (\Exception $e) {
            }
        }
        */

        // cut out whitespace
        $html = trim($html);

        // Bail early if no HTML at all
        if($html === "") {
            return "";
        }

        $use_domdocument = Config::inst()->get( Requirements::class, 'use_domdocument' );
        if($use_domdocument) {
            //use requirements insertion via DOMDocument
            $html = $this->includeInHTMLViaDOMDocument($html);
        } else {

            if (
                (strpos($html, '</head>') !== false || strpos($html, '</head ') !== false)
                && ($this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags)
            ) {

                // Combine files - updates $this->javascript and $this->css
                $this->processCombinedFiles();

                // Blocking CSS by default
                $lazy_css_requirements = $css_requirements = '';
                $this->applyLinkTags($lazy_css_requirements, $css_requirements);

                // script requirements
                $script_requirements = $this->applyScriptTags();

                // inline styles
                $css_requirements = $this->applyStyleTags($css_requirements);

                // custom head tags
                $head_requirements = $this->applyHeadTags();

                // add scripts
                if (strpos($html, self::LOADER_SCRIPT_PLACEHOLDER) !== false) {
                    // Attempt to replace the placeholder comment with our scripts
                    $script_requirements .= "<!-- :) -->";
                    $html = str_replace(self::LOADER_SCRIPT_PLACEHOLDER, $script_requirements, $html);
                } else {
                    // No placeholder: push in prior to </body>
                    $html = preg_replace("/(<\/body[^>]*>)/i", $script_requirements . "\\1", $html);
                }

                if ($lazy_css_requirements) {
                    // Lazy css requirements end up as <link> tags before the </body> - non critical CSS
                    $html = preg_replace("/(<\/body[^>]*>)/i", $lazy_css_requirements . "\\1", $html);
                }

                if ($css_requirements) {
                    // Put standard CSS requirements at base of </head>
                    $html = preg_replace("/(<\/head>)/i", $css_requirements . "\\1", $html);
                }

                if ($head_requirements) {
                    // Put <head> requirements at base of </head>
                    $html = preg_replace("/(<\/head>)/i", $head_requirements . "\\1", $html);
                }
            }

        }
        return $html;
    }

    /**
     * Use DOMDocument to insert requirements
     * @param string $html
     * @returns string HTML possibly with some requirements added
     */
    private function includeInHTMLViaDOMDocument($html) {

        $html = trim($html);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML( $html , LIBXML_HTML_NODEFDTD );

        $head = $dom->getElementsByTagName('head')[0];
        if(!$head) {
            // no <head> in HTML provided
            return $html;
        }

        $body = $dom->getElementsByTagName('body')[0];

        // check for requirements
        if ($this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags) {

            // Combine files - updates $this->javascript and $this->css
            $this->processCombinedFiles();

            // Apply tags
            $lazy_css_requirements = $css_requirements = '';
            $this->applyLinkTags($lazy_css_requirements, $css_requirements);

            // script requirements
            $script_requirements = $this->applyScriptTags();

            // Apply inline styles
            $css_requirements = $this->applyStyleTags($css_requirements);

            // custom head tags
            $head_requirements = $this->applyHeadTags();

            $fragment = new DOMDocument();

            if ($script_requirements) {
                // add scripts
                $fragment->loadHTML( $script_requirements, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
                foreach($fragment->getElementsByTagName('script') as $script) {
                    $body->appendChild( $dom->importNode( $script, true) );
                }
            }

            if ($lazy_css_requirements) {
                // Lazy css requirements end up as <link> tags before the </body> - non critical CSS
                $fragment->loadHTML( $lazy_css_requirements, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
                foreach($fragment->getElementsByTagName('link') as $link) {
                    $body->appendChild( $dom->importNode( $link, true) );
                }
            }

            if ($css_requirements) {
                // Put standard CSS requirements at base of </head>
                $fragment->loadHTML( $css_requirements, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
                foreach($fragment->getElementsByTagName('link') as $link) {
                    $head->appendChild( $dom->importNode( $link, true) );
                }
            }

            if ($head_requirements) {
                // Put <head> requirements at base of </head>
                $fragment->loadHTML( $head_requirements, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
                foreach($fragment->getElementsByTagName('*') as $node) {
                    $head->appendChild( $dom->importNode( $node, true) );
                }
            }

        }

        $html = $dom->saveHTML();
        libxml_clear_errors();

        return $html;
    }

    /**
     * Apply script tags
     * @return string
     */
    protected function applyScriptTags() {

        // Collect script paths
        $script_paths = [];

        foreach (array_diff_key($this->javascript, $this->blocked) as $file => $options) {
            $script_paths[$this->pathForFile($file)] = $options;
        }

        // load the loader
        $async_script = $this->asyncLoader();
        $async_script .= "\n\n";

        // load up required javascript
        $async_script .= $this->asyncScriptLoader($script_paths);
        $async_script .= "\n";

        // Run any specific bundles that are declared, including the default one
        $async_script .= $this->addBundleScripts();
        $async_script .= "\n";

        // and CSS bundles via loadjs
        $async_script .= $this->addBundleStylesheets();

        // remove whitespace
        $async_script = trim($async_script);

        // register the script
        Requirements::customScript($async_script, "async_script_loading");

        // SRI hash
        $attributes = [];
        $attributes['crossorigin'] = 'anonymous';
        $attributes['integrity'] = $this->createSubResourceIntegrityHash( $async_script );
        $tag = HTML::createTag('script', $attributes, $async_script);

        return $tag;

    }

    /**
     * Apply inline CSS requirements
     * These are pushed to the <head>, after linked stylesheets
     * @param string
     * @return string
     */
    protected function applyStyleTags($css_requirements) {
        foreach (array_diff_key($this->customCSS, $this->blocked) as $uniquenessID => $css) {
            $attributes = [];
            $attributes['type'] = 'text/css';
            $tag = HTML::createTag('style', $attributes, $css);
            $css_requirements .= $tag;
        }
        return $css_requirements;
    }

    /**
     * Apply linked stylesheets
     * @param string $lazy_css_requirements - CSS that is not blocking
     * @param string $css_requirements - CSS that is blocking
     * @return void
     */
    protected function applyLinkTags(&$lazy_css_requirements, &$css_requirements) {

        $lazy_css_requirements = $css_requirements = "";

        // Blocking CSS by default
        foreach (array_diff_key($this->css, $this->blocked) as $file => $options) {
            $path = $this->pathForFile($file);
            if ($path) {

                $attributes = [
                    'rel' => 'stylesheet',
                    'type' => 'text/css',
                    'href' => $path
                ];

                if(isset($options['media'])) {
                    $attributes['media'] = $options['media'];
                }

                if(isset($options['integrity'])) {
                    $attributes['integrity'] = $options['integrity'];
                }

                if(isset($options['crossorigin'])) {
                    $attributes['crossorigin'] = $options['crossorigin'];
                }

                $tag = HTML::createTag('link', $attributes, '');

                if (isset($options['lazy'])) {
                    $lazy_css_requirements .= $tag . "\n";
                } else {
                    $css_requirements .= $tag . "\n";
                }
            }
        }
    }

    /**
     * Apply custom head tags
     * @return string
     */
    protected function applyHeadTags() {
        $head_requirements = "";
        foreach (array_diff_key($this->customHeadTags, $this->blocked) as $customHeadTag) {
            $head_requirements .= $customHeadTag . "\n";
        }
        return $head_requirements = "";
    }

    /**
     * Create an SRI hash based on a string
     * @param string $string
     * @return string
     */
    protected function createSubResourceIntegrityHash(string $string) {
        $algo = $this->config()->get('algo');
        $algos = ['sha256','sha384','sha512'];
        if(!in_array($algo, $algos)) {
            $algo = 'sha512';
        }
        return $algo . "-" . base64_encode( hash( $algo, $string, true) );
    }
}
