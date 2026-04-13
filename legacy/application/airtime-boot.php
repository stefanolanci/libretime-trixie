<?php

//  Only enable cookie secure if we are supporting https.
//  Ideally, this would always be on and we would force https,
//  but the default installation configs are likely to be installed by
//  amateur users on a setup that does not have https.  Forcing
//  cookie_secure on non https would result in confusing login problems.
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.cookie_httponly', '1');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

function exception_error_handler($errno, $errstr, $errfile, $errline)
{
    if (0 === error_reporting()) {
        return false;
    }

    // Deprecations (ZF1, Propel 1, PHP 8.x changes) are suppressed at the
    // error_reporting level above; if one still reaches this handler let it
    // pass through to the default logger without becoming an exception.
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return false;
    }

    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}

set_error_handler('exception_error_handler');

// Ensure library/ is on include_path
set_include_path(implode(PATH_SEPARATOR, [
    get_include_path(),
    realpath(LIB_PATH),
]));

set_include_path(APPLICATION_PATH . '/common' . PATH_SEPARATOR . get_include_path());
set_include_path(APPLICATION_PATH . '/common/enum' . PATH_SEPARATOR . get_include_path());
set_include_path(APPLICATION_PATH . '/common/interface' . PATH_SEPARATOR . get_include_path());

// Propel classes.
set_include_path(APPLICATION_PATH . '/models' . PATH_SEPARATOR . get_include_path());

// Controllers.
set_include_path(APPLICATION_PATH . '/controllers' . PATH_SEPARATOR . get_include_path());

// Controller plugins.
set_include_path(APPLICATION_PATH . '/controllers/plugins' . PATH_SEPARATOR . get_include_path());

// Services.
set_include_path(APPLICATION_PATH . '/services' . PATH_SEPARATOR . get_include_path());

// Upgrade directory
set_include_path(APPLICATION_PATH . '/upgrade' . PATH_SEPARATOR . get_include_path());

// Common directory
set_include_path(APPLICATION_PATH . '/common' . PATH_SEPARATOR . get_include_path());

/** Zend_Application */
$application = new Zend_Application(
    APPLICATION_ENV,
    CONFIG_PATH . '/application.ini',
    true
);

require_once APPLICATION_PATH . '/logging/Logging.php';
Logging::setLogPath(LIBRETIME_LOG_FILEPATH);
Logging::setupParseErrorLogging();

// Create application, bootstrap, and run
try {
    $sapi_type = php_sapi_name();
    if (substr($sapi_type, 0, 3) == 'cli') {
        set_include_path(APPLICATION_PATH . PATH_SEPARATOR . get_include_path());

        require_once 'Bootstrap.php';
    } else {
        $application->bootstrap()->run();
    }
} catch (Exception $e) {
    if ($e->getCode() == 401) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized', true, 401);

        return;
    }

    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    Logging::error($e->getMessage());

    if (VERBOSE_STACK_TRACE) {
        echo $e->getMessage();
        echo '<pre>';
        echo $e->getTraceAsString();
        echo '</pre>';
        Logging::error($e->getMessage());
        Logging::error($e->getTraceAsString());
    } else {
        Logging::error($e->getTrace());
    }

    throw $e;
}
