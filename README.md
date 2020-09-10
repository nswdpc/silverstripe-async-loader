# Async Requirements Loader

This module provides a backend to load requirements asynchronously using [loadjs](https://github.com/muicss/loadjs)

The goal is to make requirements loading light without blocking page load and to support the separation of critical and non-critical requirements.

> loadjs itself is loaded as an inline script within the page

Supports Silverstripe 4.0+

## Usage example


In configuration:


```php
// /path/to/your/project/_config.php
use SilverStripe\View\Requirements;
Requirements::set_backend( NSWDPC\AsyncLoader\Backend::create() );
```


In a specific controller:

```php
use SilverStripe\View\Requirements;

// load

$backend = NSWDPC\AsyncLoader\Backend::create();
Requirements::set_backend($backend);

// load requirements in the default bundle (blocking)
Requirements::javascript('path/to/some/requirement.js');
Requirements::javascript('https://example.com/lib_depending_on_requirement.js');
Requirements::javascript('//example.com/load_over_current_protocol.js');

// block requirements as usual
Requirements::block('/something_you_want_to_block.js');

// Example: create a specific bundle called 'threejs'
$backend->bundle(
    'threejs', [
        'https://cdnjs.cloudflare.com/ajax/libs/three.js/r120/three.min.js' => [
            // options
            'integrity' => 'sha512-kgjZw3xjgSUDy9lTU085y+UCVPz3lhxAtdOVkcO4O2dKl2VSBcNsQ9uMg/sXIM4SoOmCiYfyFO/n1/3GSXZtSg==',
            'crossorigin' => 'anonymous'
        ]
    ]
);

```

## Bundles

loadjs uses dependency 'bundles' to manage loading of scripts.

### Default requirements bundle
For backwards compatibility scripts loaded via ```Requirements::javascript``` are loaded by loadjs in series and assigned to the bundle 'requirements_bundle'

> See [Section 3](https://github.com/muicss/loadjs#documentation) of the loadjs documentation

Once all scripts are loaded, a callback ```loadjs_ready_requirements_bundle``` is fired and dispatches a DOM event ```load_requirements_bundle```

Custom scripts that require a script to be loaded prior to firing can listen for this DOM event:
```javascript
document.addEventListener('load_requirements_bundle', function(e) {
	// custom script
}
```

### Specific bundles

Scripts that do not depend on anything loaded in a default bundle can be loaded in a non blocking way in their own bundle:
```php
// load fontawesome
$backend->bundle(
    'fontawesome', [
        'https://use.fontawesome.com/fa_code.js' => [
            // options
        ]
    ]
);
```

Optionally with a callback... if you need to do something after a bundle loads
> See [Section 1](https://github.com/muicss/loadjs#documentation) of the loadjs documentation

```php
// load fontawesome bundle
$backend->bundle(
    'fontawesome', [
        'https://use.fontawesome.com/fa_code.js' => [
            // options
        ]
    ],
    "console.log('fontawesome loaded!');" // success
);
```

You can include multiple scripts in the bundle
> See [Section 2](https://github.com/muicss/loadjs#documentation) of the loadjs documentation

```php
// load one and two asynchronously (two.js may load before one.js)
$backend->bundle(
    'fontawesome', [
        '/script/one.js' => [
            // options
        ],
        '/script/two.js' => [
            // options
        ]
    ],
    "console.log('one and two loaded!');"// success callback javascript
);
```

## CSS

CSS requirements are loaded in blocking mode by default within the `<head>` tag.

You can alternatively load CSS in non-blocking mode via a backend bundle:

```php
// load CSS without blocking
$backend->bundle_css(
    'css_bundle', [
        'path/to/style.css'
    ]
);
```

Be aware that unless you load in styles that set up a basic/acceptable above-the-fold layout, you will most likely get a FOUC until stylesheets loaded this way are applied.
It's fast, but ugly.

> Refer to [Section 5](https://github.com/muicss/loadjs#documentation) of the loadjs documentation on CSS loading notes.

## Page placement

If you wish, place the HTML comment `<!-- asyncloader_script_requirements_placeholder -->` in your page template where you would like JS requirements to be placed.
The backend will replace this comment with the JS requirements found. If you do not do this, the JS requirements will be loaded before the closing body tag.

## Custom scripts
If you have custom scripts that assume a library is loaded, these will most likely fail unless they are run after their dependency loads.
To ensure that they run correctly, apply them in the relevant bundle callback or in an event listener for ```load_requirements_bundle```

## Custom head tags
These are loaded prior to the closing head tag.

## TODO
* For non-critical CSS, support for 'lazy' loading of Requirements::css() placing a stylesheet ```<link>``` tag prior to the closing body tag.

## Licences

BSD 3-Clause

* loadjs is licensed under the MIT license: https://github.com/muicss/loadjs/blob/master/LICENSE.txt
