<?php

/**
 * install CaseBox script designed to help first configuration of casebox
 *
 * this script can be run in interactive mode (default)
 * or specify an input ini file using -f option
 *
 * For tests this script can be included and $options variable
 * can be predefined before include.
 *
 * $options can contain (f or file) property to indicate configuration ini file used
 * or directly a 'config' array property that will have all needed params set
 *
 * Requirements:
 *     on Windows platform path to mysql/bin should be added to "Path" environment variable
 */
namespace CB;

/* check if we are running under root / Administrator user */
$currentUser = empty($_SERVER['USER'])
    ? @$_SERVER['USERNAME']
    : $_SERVER['USER'];

if (!in_array($currentUser, array('root', 'Administrator'))) {
    trigger_error('This script should be run under "root" or "Administrator"', E_USER_ERROR);
}

/*define some basic directories*/
$binDirectorty = dirname(__FILE__) . DIRECTORY_SEPARATOR;
$cbHome = dirname($binDirectorty) . DIRECTORY_SEPARATOR;

if (!isset($cfg)) {
    $cfg = array();
}

// we include config_platform that will load config.ini if exist and will define $cfg variable
// If config.ini doesnt exist it wil raise an exception: Can't load config file

try {
    require_once $cbHome . 'httpsdocs/config_platform.php';
} catch (\Exception $e) {
    //config.ini could not exist

    //we don't need to do anything here because this script will create confing.ini in result
    //we just use values form config.ini as defaults, if it exists
}

// detect working mode (interactive or not)
if (empty($options)) {
    $options = getopt('f:', array('file:'));
}

$configFile = empty($options['f'])
    ? @$options['file']
    : $options['f'];

if (!empty($configFile)) {
    $options['config'] = Config::loadConfigFile($configFile);
}

Cache::set('inCfg', $options['config']);

//define working mode
if (!empty($options['config'])) {
    // define('CB\Cache::get('RUN_SETUP_INTERACTIVE_MODE')', false);
    Cache::set('RUN_SETUP_INTERACTIVE_MODE', false);
    // $cfg = $options['config'];

} else {
  //  define('CB\Cache::get('RUN_SETUP_INTERACTIVE_MODE')', true);
    Cache::set('RUN_SETUP_INTERACTIVE_MODE', true);
}

require_once 'install_functions.php';

displaySystemNotices();

// initialize default values in cofig if not detected

$defaultValues = getDefaultConfigValues();

$cfg = $cfg + $defaultValues;

if (!IS_WINDOWS) {
    //ask for apache user and set ownership for some folders
    $cfg['apache_user'] = readParam('apache_user', $cfg['apache_user']);
    setOwnershipForApacheUser($cfg);
}

//init prefix
$cfg['prefix'] = readParam('prefix', $cfg['prefix']);

//init db config
do {
    initDBConfig($cfg);
} while (!verifyDBConfig($cfg));

//specify server_name
$l = readParam('server_name', $cfg['server_name']);

//add trailing slash
if (!empty($l)) {
    $l = trim($l);
    if (substr($l, -1) != '/') {
        $l .= '/';
    }

    $cfg['server_name'] = $l;
}

//init solr connection
initSolrConfig($cfg);

$cfg['admin_email'] = readParam('admin_email', $cfg['admin_email']);
$cfg['sender_email'] = readParam('sender_email', $cfg['sender_email']);

//define comments email params
if (confirm('define_comments_email')) {
    $cfg['comments_email'] = readParam('comments_email', $cfg['comments_email']);
    $cfg['comments_host'] = readParam('comments_host', $cfg['comments_host']);
    $cfg['comments_port'] = readParam('comments_port', $cfg['comments_port']);
    $cfg['comments_ssl'] = readParam('comments_ssl', $cfg['comments_ssl']);
    $cfg['comments_user'] = readParam('comments_user', $cfg['comments_user']);
    $cfg['comments_pass'] = readParam('comments_pass');
} else {
    unset($cfg['comments_email']);
    unset($cfg['comments_host']);
    unset($cfg['comments_port']);
    unset($cfg['comments_ssl']);
    unset($cfg['comments_user']);
    unset($cfg['comments_pass']);
}

$cfg['PYTHON'] = readParam('PYTHON', $cfg['PYTHON']);

$cfg['backup_dir'] = readParam('backup_dir', $cfg['backup_dir']);

//define BACKUP_DIR constant and create corresponding directory
defineBackupDir($cfg);

echo "\nYou have configured main options for casebox.\n" .
    "Saving your settings to casebox.ini ... ";

backupFile(DOC_ROOT . 'config.ini');

do {
    $r = putIniFile(
        DOC_ROOT . 'config.ini',
        array_intersect_key($cfg, $defaultValues)
    );

    if ($r === false) {
        if (Cache::get('RUN_SETUP_INTERACTIVE_MODE')) {
            $r = !confirm('error saving to config.ini file. retry [Y/n]: ');
        } else {
            trigger_error('Error saving to config.ini file', E_USER_ERROR);
        }
    } else {
        echo "Ok\n\n";
    }
} while ($r === false);

//---------- create solr symlinks for casebox config sets
if (createSolrConfigsetsSymlinks($cfg)) {
    echo "Solr configsets symlinks created sucessfully.\n\r";
} else {
    echo "Error creating symlinks to solr configsets.\n\r";
}

//try to create log core

createSolrCore($cfg, '_log', 'log_');

//create default database (<prefix>__casebox)
createMainDatabase($cfg);

echo 'Creating language files .. ';
exec('php "' . $binDirectorty . 'languages_update_js_files.php"');

echo "Ok\n\nCasebox was successfully configured on your system\n" .
    "you should create at least one Core to use it.\n";

//ask if new core instance needed
if (confirm('create_basic_core')) {
    $l = readParam('core_name');
    if (!empty($l)) {
        $options = array(
            'core' => $l
            ,'sql' => APP_DIR . 'install/mysql/bare_bone_core.sql'
        );

        include $binDirectorty . 'core_create.php';
    }

}
echo "Done\n";
