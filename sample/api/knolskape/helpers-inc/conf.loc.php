<?php

Helpers::$session_name = 'ctpsession';

Helpers::debug_mode(true);

Helpers::$use_db = 'test';

Helpers::no_cache_headers();

define('DEV_MODE', true);
