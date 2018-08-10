<?php
namespace NSWDPC\AsyncLoader;
use Silverstripe\View\Requirements_Backend;
use Silverstripe\Core\Convert;
use Exception;

class Backend extends Requirements_Backend {

	const LOADER_URL = "https://cdnjs.cloudflare.com/ajax/libs/loadjs/3.5.1/loadjs.js";
	// v3.5.1 of the above URL
	const LOADER_SCRIPT = <<<LOADJS
loadjs=function(){function e(e,n){var t,r,i,c=[],o=(e=e.push?e:[e]).length,f=o;for(t=function(e,t){t.length&&c.push(e),--f||n(c)};o--;)r=e[o],(i=s[r])?t(r,i):(u[r]=u[r]||[]).push(t)}function n(e,n){if(e){var t=u[e];if(s[e]=n,t)for(;t.length;)t[0](e,n),t.splice(0,1)}}function t(e,n,r,i){var o,s,u=document,f=r.async,a=(r.numRetries||0)+1,h=r.before||c;i=i||0,/(^css!|\.css$)/.test(e)?(o=!0,(s=u.createElement("link")).rel="stylesheet",s.href=e.replace(/^css!/,"")):((s=u.createElement("script")).src=e,s.async=void 0===f||f),s.onload=s.onerror=s.onbeforeload=function(c){var u=c.type[0];if(o&&"hideFocus"in s)try{s.sheet.cssText.length||(u="e")}catch(e){u="e"}if("e"==u&&(i+=1)<a)return t(e,n,r,i);n(e,u,c.defaultPrevented)},!1!==h(e,s)&&u.head.appendChild(s)}function r(e,n,r){var i,c,o=(e=e.push?e:[e]).length,s=o,u=[];for(i=function(e,t,r){if("e"==t&&u.push(e),"b"==t){if(!r)return;u.push(e)}--o||n(u)},c=0;c<s;c++)t(e[c],i,r)}function i(e,t,i){var s,u;if(t&&t.trim&&(s=t),u=(s?i:t)||{},s){if(s in o)throw"LoadJS";o[s]=!0}r(e,function(e){e.length?(u.error||c)(e):(u.success||c)(),n(s,e)},u)}var c=function(){},o={},s={},u={};return i.ready=function(n,t){return e(n,function(e){e.length?(t.error||c)(e):(t.success||c)()}),i},i.done=function(e){n(e,[])},i.reset=function(){o={},s={},u={}},i.isDefined=function(e){return e in o},i}();
LOADJS;

	const LOADER_SCRIPT_PLACEHOLDER = "<!-- asyncloader_script_requirements_placeholder -->";// put this comment where you want scripts to load

	protected $bundle_name = 'requirements_bundle';
	protected $bundle_name_css = 'requirements_bundle_css';

	protected $bundle_scripts, $bundle_stylesheets = [];

	/**
	 * Stores a JS bundle linked to $bundle_name
	 */
	public function bundle($bundle_name, array $scripts, $success = '') {
		if(!$bundle_name) {
			$bundle_name = "bundle_" . md5(implode(",", $scripts));
		}
		$this->bundle_scripts[$bundle_name] = [ 'scripts' => $scripts, 'success' => $success ];
	}

	/**
	 * Stores a CSS bundle linked to $bundle_name
	 */
	public function bundle_css($bundle_name, array $stylesheets, $success = '') {
		if(!$bundle_name) {
			$bundle_name = "bundle_" . md5(implode(",", $stylesheets));
		}
		$this->bundle_stylesheets[$bundle_name] = [ 'stylesheets' => $stylesheets, 'success' => $success ];
	}

	/**
	 * Returns some plain ol' Javascript that will dispatch an event when the named bundle has loaded
	 */
	private function bundleDispatch($bundle, $error = false) {
		$event_name = "load_{$bundle}" . ($error ? "_error" : "");
		$dispatcher = <<<JAVASCRIPT
try {
	var event = new CustomEvent('$event_name', {bubbles: true, cancelable: true});
} catch (e) {
	var event = document.createEvent('Event');
	event.initEvent('$event_name', true, true); //can bubble, and is cancellable
}
document.dispatchEvent(event);
JAVASCRIPT;
			return $dispatcher;
	}

	/**
	 * Return loadjs itself
	 */
	private function asyncLoader() {
		$script = "<script>" . self::LOADER_SCRIPT . "</script>\n";
		return $script;
	}

	/**
	 * Load the scripts requested via Requirements::javascript, in the bundle 'requirements_bundle'
	 * @note to avoid massive refactor, these scripts are loaded in the order of requirement with async set to false - loadjs will load them in parallel but executee them in series
	 */
	private function asyncScriptLoader($scripts) {
		$loader_scripts = "";
		// Hit up custom scripts after the requirements_bundle has loaded
		$loader_scripts .= "<script>\n";
		$loader_scripts .= "var loadjs_ready_{$this->bundle_name} = function() {\n";

		// dispatch the event when the bundle_name has loaded successfully
		$loader_scripts .= $this->bundleDispatch($this->bundle_name) . "\n";

		if($this->customScript) {
			foreach(array_diff_key($this->customScript,$this->blocked) as $script) {
				$loader_scripts .= "{$script}\n\n";
			}
		}
		$loader_scripts .= "};\n";

		if(empty($scripts)) {
			// No scripts, notify custom scripts
			$loader_scripts .= "loadjs_ready_{$this->bundle_name}();\n";
		} else {
			$loader_scripts .= "loadjs(\n";
			$loader_scripts .= json_encode($scripts, JSON_UNESCAPED_SLASHES);
			$loader_scripts .= ", '{$this->bundle_name}'";
			$loader_scripts .= ", {\n";
			$loader_scripts .= "async: false,\n";
			$loader_scripts .= "success: function() { loadjs_ready_{$this->bundle_name}(); },\n";
			$loader_scripts .= "error: function(nf) {}\n";
			//$loader_scripts .= ",before: function(path, elem) {}";
			$loader_scripts .= "});\n";
		}

		$loader_scripts .= "</script>\n";
		return $loader_scripts;
	}

	/**
	 * Include any specific script bundles that are found
	 */
	private function addBundleScripts() {
		if(empty($this->bundle_scripts)) {
			return "";
		}
		// load all bundle scripts
		$scripts = "<script>\n";
		foreach($this->bundle_scripts as $bundle_name => $bundle) {
			if($bundle_name == $this->bundle_name) {
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
		$scripts .= "</script>\n";
		return $scripts;
	}

	/**
	 * Include any specific stylesheet bundles that are found
	 */
	private function addBundleStylesheets() {
		if(empty($this->bundle_stylesheets)) {
			return "";
		}
		$stylesheets = "<script>\n";
		foreach($this->bundle_stylesheets as $bundle_name => $bundle) {
			if($bundle_name == $this->bundle_name_css) {
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
		$stylesheets = "</script>\n";
		return $stylesheets;
	}

	/**
	 * Update the given HTML content with the appropriate include tags for the registered
	 * requirements. Needs to receive a valid HTML/XHTML template in the $content parameter,
	 * including a head and body tag.
	 *
	 * @param string $content      HTML content that has already been parsed from the $templateFile
	 *                             through {@link SSViewer}
	 * @return string HTML content augmented with the requirements tags
	 */
	public function includeInHTML($content) {
		if(
			(strpos($content, '</head>') !== false || strpos($content, '</head ') !== false)
			&& ($this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags)
		) {

			$head_requirements = '';
			$lazy_css_requirements = $css_requirements = '';
			$script_requirements = '';

			// Combine files - updates $this->javascript and $this->css
			$this->processCombinedFiles();

			// Collect script paths
			$script_paths = [];
			foreach(array_diff_key($this->javascript,$this->blocked) as $file => $dummy) {
				$script_paths[] = Convert::raw2xml($this->pathForFile($file));
			}

			// load the loader
			$script_requirements = $this->asyncLoader();

			// load up required javascript
			$script_requirements .= $this->asyncScriptLoader($script_paths);

			// Run any specific bundles that are declared
			$script_requirements .= $this->addBundleScripts();

			// Blocking CSS by default
			foreach(array_diff_key($this->css,$this->blocked) as $file => $params) {
				$path = Convert::raw2xml($this->pathForFile($file));
				if($path) {
					$media = (isset($params['media']) && !empty($params['media'])) ? " media=\"{$params['media']}\"" : "";

					if(isset($params['lazy'])) {
						$lazy_css_requirements .= "<link rel=\"stylesheet\" type=\"text/css\"{$media} href=\"$path\" />\n";
					} else {
						$css_requirements .= "<link rel=\"stylesheet\" type=\"text/css\"{$media} href=\"$path\" />\n";
					}
				}
			}

			// and CSS bundles via loadjs
			$script_requirements .= $this->addBundleStylesheets();

			// inline CSS requirements are pushed to the <head>, after linked stylesheets
			foreach(array_diff_key($this->customCSS, $this->blocked) as $css) {
				$css_requirements .= "<style type=\"text/css\">\n$css\n</style>\n";
			}

			foreach(array_diff_key($this->customHeadTags,$this->blocked) as $customHeadTag) {
				$head_requirements .= $customHeadTag . "\n";
			}

			// add scripts
			if(strpos($content, self::LOADER_SCRIPT_PLACEHOLDER)) {
				// Attempt to replace the placeholder comment with our scripts
				$script_requirements .= "<!-- :) -->";
				$content = str_replace(self::LOADER_SCRIPT_PLACEHOLDER, $script_requirements, $content);
			} else {
				// No placeholder: push in prior to </body>
				$content = preg_replace("/(<\/body[^>]*>)/i", $script_requirements . "\\1", $content);
			}

			if($lazy_css_requirements) {
				// Lazy css requirements end up as <link> tags before the </body> - non critical CSS
				$content = preg_replace("/(<\/body[^>]*>)/i", $lazy_css_requirements . "\\1", $content);
			}

			if($css_requirements) {
				// Put standard CSS requirements at base of </head>
				$content = preg_replace("/(<\/head>)/i", $css_requirements . "\\1", $content);
			}

			if($head_requirements) {
				// Put <head> requirements at base of </head>
				$content = preg_replace("/(<\/head>)/i", $head_requirements . "\\1", $content);
			}

		}

		return $content;
	}
}
