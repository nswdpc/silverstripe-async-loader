<?php
namespace NSWDPC\AsyncLoader;
use Silverstripe\View\Requirements_Backend;
use Silverstripe\Core\Convert;
use Exception;

class Backend extends Requirements_Backend {

	const LOADER_URL = "https://cdnjs.cloudflare.com/ajax/libs/loadjs/3.5.4/loadjs.js";
	// v3.5.4 of the above URL
	const LOADER_SCRIPT = <<<LOADJS
loadjs=function(){var l=function(){},c={},f={},u={};function s(e,n){if(e){var t=u[e];if(f[e]=n,t)for(;t.length;)t[0](e,n),t.splice(0,1)}}function o(e,n){e.call&&(e={success:e}),n.length?(e.error||l)(n):(e.success||l)(e)}function h(t,r,i,c){var s,o,e=document,n=i.async,f=(i.numRetries||0)+1,u=i.before||l,a=t.replace(/^(css|img)!/,"");c=c||0,/(^css!|\.css$)/.test(t)?(s=!0,(o=e.createElement("link")).rel="stylesheet",o.href=a):/(^img!|\.(png|gif|jpg|svg)$)/.test(t)?(o=e.createElement("img")).src=a:((o=e.createElement("script")).src=t,o.async=void 0===n||n),!(o.onload=o.onerror=o.onbeforeload=function(e){var n=e.type[0];if(s&&"hideFocus"in o)try{o.sheet.cssText.length||(n="e")}catch(e){n="e"}if("e"==n&&(c+=1)<f)return h(t,r,i,c);r(t,n,e.defaultPrevented)})!==u(t,o)&&e.head.appendChild(o)}function t(e,n,t){var r,i;if(n&&n.trim&&(r=n),i=(r?t:n)||{},r){if(r in c)throw"LoadJS";c[r]=!0}!function(e,r,n){var t,i,c=(e=e.push?e:[e]).length,s=c,o=[];for(t=function(e,n,t){if("e"==n&&o.push(e),"b"==n){if(!t)return;o.push(e)}--c||r(o)},i=0;i<s;i++)h(e[i],t,n)}(e,function(e){o(i,e),s(r,e)},i)}return t.ready=function(e,n){return function(e,t){e=e.push?e:[e];var n,r,i,c=[],s=e.length,o=s;for(n=function(e,n){n.length&&c.push(e),--o||t(c)};s--;)r=e[s],(i=f[r])?n(r,i):(u[r]=u[r]||[]).push(n)}(e,function(e){o(n,e)}),t},t.done=function(e){s(e,[])},t.reset=function(){c={},f={},u={}},t.isDefined=function(e){return e in c},t}();
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
		$loader_scripts .= "};\n";

		if(!empty($this->customScript)) {
			$loader_scripts .= "//cs:start\n";
			foreach(array_diff_key($this->customScript,$this->blocked) as $script) {
				$loader_scripts .= "{$script}\n";
			}
			$loader_scripts .= "//cs:end\n";
		}

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
				$script_paths[] = $this->pathForFile($file);
			}

			// load the loader
			$script_requirements = $this->asyncLoader();

			// load up required javascript
			$script_requirements .= $this->asyncScriptLoader($script_paths);

			// Run any specific bundles that are declared
			$script_requirements .= $this->addBundleScripts();

			// Blocking CSS by default
			foreach(array_diff_key($this->css,$this->blocked) as $file => $params) {
				$path = $this->pathForFile($file);
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
