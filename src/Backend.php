<?php
namespace NSWDPC\AsyncLoader;

use SilverStripe\View\Requirements;
use Silverstripe\View\Requirements_Backend;
use Silverstripe\Core\Convert;
use SilverStripe\Core\Config\Config;
use Exception;
use DOMDocument;

class Backend extends Requirements_Backend
{
    const LOADER_URL = "https://cdnjs.cloudflare.com/ajax/libs/loadjs/3.6.0/loadjs.min.js";
    // content of the above URL
    const LOADER_SCRIPT = <<<LOADJS
loadjs=function(){var a=function(){},c={},u={},f={};function o(e,n){if(e){var t=f[e];if(u[e]=n,t)for(;t.length;)t[0](e,n),t.splice(0,1)}}function l(e,n){e.call&&(e={success:e}),n.length?(e.error||a)(n):(e.success||a)(e)}function h(t,r,s,i){var c,o,e=document,n=s.async,u=(s.numRetries||0)+1,f=s.before||a,l=t.replace(/^(css|img)!/,"");i=i||0,/(^css!|\.css$)/.test(t)?((o=e.createElement("link")).rel="stylesheet",o.href=l,(c="hideFocus"in o)&&o.relList&&(c=0,o.rel="preload",o.as="style")):/(^img!|\.(png|gif|jpg|svg)$)/.test(t)?(o=e.createElement("img")).src=l:((o=e.createElement("script")).src=t,o.async=void 0===n||n),!(o.onload=o.onerror=o.onbeforeload=function(e){var n=e.type[0];if(c)try{o.sheet.cssText.length||(n="e")}catch(e){18!=e.code&&(n="e")}if("e"==n){if((i+=1)<u)return h(t,r,s,i)}else if("preload"==o.rel&&"style"==o.as)return o.rel="stylesheet";r(t,n,e.defaultPrevented)})!==f(t,o)&&e.head.appendChild(o)}function t(e,n,t){var r,s;if(n&&n.trim&&(r=n),s=(r?t:n)||{},r){if(r in c)throw"LoadJS";c[r]=!0}function i(n,t){!function(e,r,n){var t,s,i=(e=e.push?e:[e]).length,c=i,o=[];for(t=function(e,n,t){if("e"==n&&o.push(e),"b"==n){if(!t)return;o.push(e)}--i||r(o)},s=0;s<c;s++)h(e[s],t,n)}(e,function(e){l(s,e),n&&l({success:n,error:t},e),o(r,e)},s)}if(s.returnPromise)return new Promise(i);i()}return t.ready=function(e,n){return function(e,t){e=e.push?e:[e];var n,r,s,i=[],c=e.length,o=c;for(n=function(e,n){n.length&&i.push(e),--o||t(i)};c--;)r=e[c],(s=u[r])?n(r,s):(f[r]=f[r]||[]).push(n)}(e,function(e){l(n,e)}),t},t.done=function(e){o(e,[])},t.reset=function(){c={},u={},f={}},t.isDefined=function(e){return e in c},t}();
LOADJS;

    const LOADER_SCRIPT_PLACEHOLDER = "<!-- asyncloader_script_requirements_placeholder -->";// put this comment where you want scripts to load

    protected $bundle_name = 'requirements_bundle';
    protected $bundle_name_css = 'requirements_bundle_css';

    protected $bundle_scripts;
    protected $bundle_stylesheets = [];

    /**
     * Stores a JS bundle linked to $bundle_name
     */
    public function bundle($bundle_name, array $scripts, $success = '')
    {
        if (!$bundle_name) {
            $bundle_name = "bundle_" . md5(implode(",", $scripts));
        }
        $this->bundle_scripts[$bundle_name] = [ 'scripts' => $scripts, 'success' => $success ];
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
     * @note to avoid massive refactor, these scripts are loaded in the order of requirement with async set to false - loadjs will load them in parallel but executee them in series
     */
    private function asyncScriptLoader($scripts)
    {
        $loader_scripts = "";
        // Hit up custom scripts after the requirements_bundle has loaded
        $loader_scripts .= "var loadjs_ready_{$this->bundle_name} = function() {\n";
        // dispatch the event when the bundle_name has loaded successfully
        $loader_scripts .= $this->bundleDispatch($this->bundle_name) . "\n";
        if (!empty($this->customScript)) {
            //$loader_scripts .= "//cs:start\n";
            foreach (array_diff_key($this->customScript, $this->blocked) as $script) {
                $loader_scripts .= "{$script}\n";
            }
            //$loader_scripts .= "//cs:end\n";
        }
        $loader_scripts .= "};\n";

        if (empty($scripts)) {
            // No scripts, notify custom scripts
            $loader_scripts .= "loadjs_ready_{$this->bundle_name}();\n";
        } else {
            $loader_scripts .= "loadjs(\n";
            $loader_scripts .= json_encode($scripts, JSON_UNESCAPED_SLASHES);
            $loader_scripts .= ", '{$this->bundle_name}'";
            $loader_scripts .= ", {\n";
            $loader_scripts .= "async: false,\n";
            $loader_scripts .= "success: function() { loadjs_ready_{$this->bundle_name}(); },\n";
            $loader_scripts .= "error: function(nf) { console.log('Not found:', nf); }\n";
            //$loader_scripts .= ",before: function(path, elem) {}";
            $loader_scripts .= "});\n";
        }

        return $loader_scripts;
    }

    /**
     * Include any specific script bundles that are found
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
            $name = json_encode($bundle_name);
            $scripts .= "loadjs(\n";
            $scripts .= json_encode($bundle['scripts'], JSON_UNESCAPED_SLASHES);
            $scripts .= ", " . $name;
            $scripts .= ", {\n";
            $scripts .= "success: function() { {$bundle['success']} },\n";
            $scripts .= "error: function(nf) {}\n";
            //$loader_scripts .= ", before: function(path, elem) {}";
            $scripts .= "});\n";
        }
        return $scripts;
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
     * @param string $html      HTML content that has already been parsed from the $templateFile
     *                             through {@link SSViewer}
     * @return string HTML content augmented with the requirements tags
     */
    public function includeInHTML($html)
    {

        if($html === "") {
            return "";
        }

        $use_domdocument = Config::inst()->get( Requirements::class, 'use_domdocument' );
        if($use_domdocument) {
            //use requirements insertion via DOMDocument
            return $this->includeInHTMLViaDOMDocument($html);
        }

        $start = microtime(true);
        if (
            (strpos($html, '</head>') !== false || strpos($html, '</head ') !== false)
            && ($this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags)
        ) {
            $head_requirements = '';
            $lazy_css_requirements = $css_requirements = '';

            // Combine files - updates $this->javascript and $this->css
            $this->processCombinedFiles();

            // Collect script paths
            $script_paths = [];
            foreach (array_diff_key($this->javascript, $this->blocked) as $file => $dummy) {
                $script_paths[] = $this->pathForFile($file);
            }

            $script_requirements = "<script>\n";

            // load the loader
            $script_requirements .= $this->asyncLoader();

            $script_requirements .= "\n\n";

            // load up required javascript
            $script_requirements .= $this->asyncScriptLoader($script_paths);

            $script_requirements .= "\n";

            // Run any specific bundles that are declared
            $script_requirements .= $this->addBundleScripts();

            $script_requirements .= "\n";

            // Blocking CSS by default
            foreach (array_diff_key($this->css, $this->blocked) as $file => $params) {
                $path = $this->pathForFile($file);
                if ($path) {
                    $media = (isset($params['media']) && !empty($params['media'])) ? " media=\"{$params['media']}\"" : "";

                    if (isset($params['lazy'])) {
                        $lazy_css_requirements .= "<link rel=\"stylesheet\" type=\"text/css\"{$media} href=\"$path\" />\n";
                    } else {
                        $css_requirements .= "<link rel=\"stylesheet\" type=\"text/css\"{$media} href=\"$path\" />\n";
                    }
                }
            }

            // and CSS bundles via loadjs
            $script_requirements .= $this->addBundleStylesheets();

            $script_requirements .= "</script>";//end script requirements

            // inline CSS requirements are pushed to the <head>, after linked stylesheets
            foreach (array_diff_key($this->customCSS, $this->blocked) as $css) {
                $css_requirements .= "<style type=\"text/css\">\n$css\n</style>\n";
            }

            foreach (array_diff_key($this->customHeadTags, $this->blocked) as $customHeadTag) {
                $head_requirements .= $customHeadTag . "\n";
            }

            // add scripts
            if (strpos($html, self::LOADER_SCRIPT_PLACEHOLDER)) {
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

        $end = microtime(true);
        $time = round($end - $start, 7);
        $html .= "<!-- {$time} -->\n";

        return $html;
    }

    /**
     * Use DOMDocument to insert requirements
     * @param string $html
     * @returns string HTML possibly with some requirements added
     */
    private function includeInHTMLViaDOMDocument($html) {

        $start = microtime(true);
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

            $head_requirements = '';
            $lazy_css_requirements = $css_requirements = '';

            // Combine files - updates $this->javascript and $this->css
            $this->processCombinedFiles();

            // Collect script paths
            $script_paths = [];
            foreach (array_diff_key($this->javascript, $this->blocked) as $file => $dummy) {
                $script_paths[] = $this->pathForFile($file);
            }

            $script_requirements = "<script>\n";

            // load the loader
            $script_requirements .= $this->asyncLoader();

            $script_requirements .= "\n\n";

            // load up required javascript
            $script_requirements .= $this->asyncScriptLoader($script_paths);

            $script_requirements .= "\n";

            // Run any specific bundles that are declared
            $script_requirements .= $this->addBundleScripts();

            $script_requirements .= "\n";

            // Blocking CSS by default
            foreach (array_diff_key($this->css, $this->blocked) as $file => $params) {
                $path = $this->pathForFile($file);
                if ($path) {
                    $media = (isset($params['media']) && !empty($params['media'])) ? " media=\"{$params['media']}\"" : "";

                    if (isset($params['lazy'])) {
                        $lazy_css_requirements .= "<link rel=\"stylesheet\" type=\"text/css\"{$media} href=\"$path\">\n";
                    } else {
                        $css_requirements .= "<link rel=\"stylesheet\" type=\"text/css\"{$media} href=\"$path\">\n";
                    }
                }
            }

            // and CSS bundles via loadjs
            $script_requirements .= $this->addBundleStylesheets();

            $script_requirements .= "</script>";//end script requirements

            // inline CSS requirements are pushed to the <head>, after linked stylesheets
            foreach (array_diff_key($this->customCSS, $this->blocked) as $css) {
                $css_requirements .= "<style type=\"text/css\">\n$css\n</style>\n";
            }

            foreach (array_diff_key($this->customHeadTags, $this->blocked) as $customHeadTag) {
                $head_requirements .= $customHeadTag . "\n";
            }

            $fragment = new DOMDocument();

            if ($script_requirements) {
                // add scripts
                $fragment->loadHTML( $script_requirements, LIBXML_HTML_NODEFDTD );
                $body->appendChild( $dom->importNode( $fragment->documentElement, true) );
            }

            if ($lazy_css_requirements) {
                // Lazy css requirements end up as <link> tags before the </body> - non critical CSS
                $fragment->loadHTML( $lazy_css_requirements, LIBXML_HTML_NODEFDTD );
                $body->appendChild( $dom->importNode( $fragment->documentElement, true) );
            }

            if ($css_requirements) {
                // Put standard CSS requirements at base of </head>
                $fragment->loadHTML( $css_requirements, LIBXML_HTML_NODEFDTD );
                $head->appendChild( $dom->importNode( $fragment->documentElement, true) );
            }

            if ($head_requirements) {
                // Put <head> requirements at base of </head>
                $fragment->loadHTML( $head_requirements, LIBXML_HTML_NODEFDTD );
                $head->appendChild( $dom->importNode( $fragment->documentElement, true) );
            }
        }
        $end = microtime(true);

        $time = round($end - $start, 7);

        $html = $dom->saveHTML();
        libxml_clear_errors();

        $html .= "<!-- {$time} -->\n";

        return $html;
    }
}
