# mastodon-api-client parser and generator

This tools/ folder contains a list of PHP-written tools that generates the mastodon-api-client code.

Start by cloning the mastodon api documentation from git@github.com:mastodon/documentation.git in a folder of your choice. Then configure the tools by creating a `bin/config.php` file with at the following: (adapt it to your need)

```php
<?php
define('MSTDN_DOC_ROOT','/path/to/your/mastodon/doc/git-clone');
```

then launch parse_entities.php and parse_methods.php

This will create updated files in tools/assets/

Then launch `bin/generate_classes.php` to generate all mastodon-api-client code.


