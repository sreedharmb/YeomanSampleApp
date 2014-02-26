<?php
// test server settings
Helpers::$session_name = 'ctpsession';

Helpers::debug_mode(true);

Helpers::$use_db = 'login';

Helpers::no_cache_headers();

define('DEV_MODE', true);

