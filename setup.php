<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2024 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include_once(__DIR__ . '/include/arrays.php');

function plugin_routerconfigs_version () {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/routerconfigs/INFO', true);
	return $info['info'];
}

function plugin_routerconfigs_install () {
	api_plugin_register_hook('routerconfigs', 'top_header_tabs',       'routerconfigs_show_tab', 'setup.php');
	api_plugin_register_hook('routerconfigs', 'top_graph_header_tabs', 'routerconfigs_show_tab', 'setup.php');
	api_plugin_register_hook('routerconfigs', 'config_arrays',         'routerconfigs_config_arrays',        'setup.php');
	api_plugin_register_hook('routerconfigs', 'draw_navigation_text',  'routerconfigs_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('routerconfigs', 'config_settings',       'routerconfigs_config_settings',      'setup.php');
	api_plugin_register_hook('routerconfigs', 'poller_bottom',         'routerconfigs_poller_bottom',        'setup.php');
	api_plugin_register_hook('routerconfigs', 'page_head',             'routerconfigs_page_head',            'setup.php');



        api_plugin_register_hook('routerconfigs', 'device_action_array',  'routerconfigs_device_action_array', 'setup.php');
        api_plugin_register_hook('routerconfigs', 'device_action_execute','routerconfigs_device_action_execute', 'setup.php');
        api_plugin_register_hook('routerconfigs', 'device_action_prepare','routerconfigs_device_action_prepare', 'setup.php');


	api_plugin_register_realm('routerconfigs', 'router-devices.php,router-accounts.php,router-backups.php,router-compare.php,router-devtypes.php', __('Router Configs', 'routerconfigs'), 1);

	routerconfigs_setup_table_new();
}

function plugin_routerconfigs_uninstall () {
	/* Do any extra Uninstall stuff here */
}

function plugin_routerconfigs_upgrade() {
	/* Here we will upgrade to the newest version */
	routerconfigs_check_upgrade();

	return false;
}

function routerconfigs_check_upgrade() {
	global $config, $database_default;

	include_once($config['library_path'] . '/database.php');
	include_once($config['library_path'] . '/functions.php');

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php','router-devices.php');
	if (!in_array(get_current_page(), $files)) {
		return;
	}

	$current = plugin_routerconfigs_version();
	$current = $current['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='routerconfigs'");

	if ($current != $old) {
		api_plugin_register_hook('routerconfigs', 'top_header_tabs',       'routerconfigs_show_tab', 'setup.php', 1);
		api_plugin_register_hook('routerconfigs', 'top_graph_header_tabs', 'routerconfigs_show_tab', 'setup.php', 1);

		/* update realms for old versions */
		if (cacti_version_compare($old,'0.2','<')) {
			api_plugin_register_realm('routerconfigs', 'router-devices.php,router-accounts.php,router-backups.php,router-compare.php', 'Plugin -> Router Configs', 1);

			/* get the realm id's and change from old to new */
			$user  = db_fetch_cell("SELECT id FROM plugin_realms WHERE file='router-devices.php'");
			if ($user >  0) {
				$users = db_fetch_assoc('SELECT user_id FROM user_auth_realm WHERE realm_id=86');

				if (sizeof($users)) {
					foreach($users as $u) {
						db_execute("INSERT INTO user_auth_realm
							(realm_id, user_id) VALUES ($user, " . $u['user_id'] . ')
							ON DUPLICATE KEY UPDATE realm_id=VALUES(realm_id)');

						db_execute('DELETE FROM user_auth_realm
							WHERE user_id=' . $u['user_id'] . "
							AND realm_id=$user");
					}
				}
			}
		}

		if (cacti_version_compare($old, '1.4.0', '<')) {
			plugin_routerconfigs_fix_backups_pre14();
		}

		if (cacti_version_compare($old, '1.5.1', '<')) {

			// Remove old columns of backups
			if (db_column_exists('plugin_routerconfigs_backups', 'config')) {
				db_execute('ALTER TABLE plugin_routerconfigs_backups
					DROP COLUMN `config`');
			}

			if (db_column_exists('plugin_routerconfigs_backups','username')) {
				db_execute('ALTER TABLE plugin_routerconfigs_backups
					CHANGE COLUMN `username` `lastuser` varchar(64)');
			}

			if (db_column_exists('plugin_routerconfigs_devices','password')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					DROP COLUMN `password`');
			}

			if (db_column_exists('plugin_routerconfigs_devices', 'anykey')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					DROP COLUMN `anykey`');
			}

			// Rename existing columns of devices
			if (db_column_exists('plugin_routerconfigs_devices','connect_type')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					CHANGE COLUMN `connect_type` `connecttype` varchar(10) DEFAULT \'\'');
			}

			if (db_column_exists('plugin_routerconfigs_devices','username')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					CHANGE COLUMN `username` `lastuser` varchar(64)');
			}

			// Add new/missing columns of devices
			if (!db_column_exists('plugin_routerconfigs_devices','connecttype')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `connecttype` varchar(10) DEFAULT \'\'');
			}

			if (!db_column_exists('plugin_routerconfigs_devices','nextbackup')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `nextbackup` int(18)');
			}

			if (!db_column_exists('plugin_routerconfigs_devices','nextattempt')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `nextattempt` int(18)');
			}

			if (!db_column_exists('plugin_routerconfigs_devices', 'timeout')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `timeout` int(18)');
			}

			if (!db_column_exists('plugin_routerconfigs_devices', 'sleep')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `sleep` int(18)');
			}

			if (!db_column_exists('plugin_routerconfigs_devices', 'elevated')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `elevated` char(3)');
			}

			// Perform tidy up of devices
			db_execute('UPDATE plugin_routerconfigs_devices SET
				nextbackup = IFNULL(nextbackup,0),
				nextattempt = IFNULL(nextattempt,0)');

			// Rename existing columns of device types
			if (db_column_exists('plugin_routerconfigs_devicetypes','connect_type')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					CHANGE COLUMN `connect_type` `connecttype` varchar(10) DEFAULT \'\'');
			}

			if (db_column_exists('plugin_routerconfigs_devicetypes','username')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					CHANGE COLUMN `username` `promptuser` varchar(64)');
			}

			if (db_column_exists('plugin_routerconfigs_devicetypes','password')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					CHANGE COLUMN `password` `promptpass` varchar(256)');
			}

			// Add new/missing columns of device types
			if (!db_column_exists('plugin_routerconfigs_devicetypes', 'anykey')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `anykey` varchar(50) DEFAULT \'\'');
			}

			if (!db_column_exists('plugin_routerconfigs_devicetypes', 'configfile')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `configfile` varchar(256) DEFAULT \'\'');
			}

			if (!db_column_exists('plugin_routerconfigs_devicetypes','connecttype')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `connecttype` varchar(10) DEFAULT \'both\'');
			}

			if (!db_column_exists('plugin_routerconfigs_devicetypes', 'sleep')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `sleep` int(18)');
			}

			if (!db_column_exists('plugin_routerconfigs_devicetypes', 'timeout')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `timeout` int(18)');
			}

			if (!db_column_exists('plugin_routerconfigs_devicetypes', 'elevated')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `elevated` char(3)');
			}
		}

		if (cacti_version_compare($old, '1.5.2', '<')) {
			if (!db_column_exists('plugin_routerconfigs_devicetypes','promptconfirm')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					ADD COLUMN `promptconfirm` varchar(64) DEFAULT \'confirm|to tftp:\'');
			}
		}

		if (cacti_version_compare($old, '1.5.3', '<')) {
			if (!db_column_exists('plugin_routerconfigs_devicetypes','promptconfirm')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devicetypes
					MODIFY COLUMN `promptconfirm` varchar(64) DEFAULT \'confirm|to tftp:\'');
			}
		}

		if (cacti_version_compare($old, '1.6.0', '<')) {
			if (!db_column_exists('pulugin_routerconfigs_devices','tftpserver')) {
				db_execute('ALTER TABLE plugin_routerconfigs_devices
					ADD COLUMN `tftpserver` varchar(255)');
			}
		}

		AddDeviceTypes();

		db_execute("UPDATE plugin_config
			SET version='$current'
			WHERE directory='routerconfigs'");
	}
}

function routerconfigs_check_dependencies() {
	global $plugins, $config;
	return true;
}

function routerconfigs_setup_table_new() {
	$data = array();
	$data['primary'] = 'id';
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Router Config Accounts';

	$data['columns'][] = array('name' => 'id', 'type' => 'int(11)', 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'name', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'username', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'password', 'type' => 'varchar(256)', 'NULL' => true);
	$data['columns'][] = array('name' => 'enablepw', 'type' => 'varchar(256)', 'NULL' => true);
	$data['columns'][] = array('name' => 'elevated', 'type' => 'varchar(3)', 'NULL' => true);

	api_plugin_db_table_create ('routerconfigs', 'plugin_routerconfigs_accounts', $data);

	$data = array();
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Router Config Backups';
	$data['primary'] = 'id';

	$data['columns'][] = array('name' => 'id', 'type' => 'int(11)', 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'btime', 'type' => 'int(18)', 'NULL' => true);
	$data['columns'][] = array('name' => 'device', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'directory', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'filename', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lastchange', 'type' => 'int(24)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lastuser', 'type' => 'varchar(64)', 'NULL' => true);

	$data['keys'][] = array('name' => 'btime', 'columns' => 'btime');
	$data['keys'][] = array('name' => 'device', 'columns' => 'device');
	$data['keys'][] = array('name' => 'directory', 'columns' => 'directory');
	$data['keys'][] = array('name' => 'lastchange', 'columns' => 'lastchange');

	api_plugin_db_table_create ('routerconfigs', 'plugin_routerconfigs_backups', $data);

	$data = array();

	$data['primary'] = 'id';
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Router Config Devices';

	$data['columns'][] = array('name' => 'id', 'type' => 'int(11)', 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'enabled', 'type' => 'varchar(2)', 'NULL' => true);
	$data['columns'][] = array('name' => 'ipaddress', 'type' => 'varchar(128)', 'NULL' => true);
	$data['columns'][] = array('name' => 'hostname', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'directory', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'account', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lastchange', 'type' => 'int(24)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lastuser', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'device', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'schedule', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lasterror', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lastbackup', 'type' => 'int(18)', 'NULL' => true);
	$data['columns'][] = array('name' => 'nextbackup', 'type' => 'int(18)', 'NULL' => true);
	$data['columns'][] = array('name' => 'lastattempt', 'type' => 'int(18)', 'NULL' => true);
	$data['columns'][] = array('name' => 'nextattempt', 'type' => 'int(18)', 'NULL' => true);
	$data['columns'][] = array('name' => 'devicetype', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'connecttype', 'type' => 'varchar(10)', 'NULL' => true);
	$data['columns'][] = array('name' => 'elevated', 'type' => 'varchar(3)', 'NULL' => true);
	$data['columns'][] = array('name' => 'sleep', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'timeout', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'debug', 'type' => 'longblob', 'NULL' => true);
	$data['columns'][] = array('name' => 'tftpserver', 'type' => 'varchar(255)', 'NULL' => true);

	$data['keys'][] = array('name' => 'enabled', 'columns' => 'enabled');
	$data['keys'][] = array('name' => 'schedule', 'columns' => 'schedule');
	$data['keys'][] = array('name' => 'ipaddress', 'columns' => 'ipaddress');
	$data['keys'][] = array('name' => 'account', 'columns' => 'account');
	$data['keys'][] = array('name' => 'lastbackup', 'columns' => 'lastbackup');
	$data['keys'][] = array('name' => 'lastattempt', 'columns' => 'lastattempt');
	$data['keys'][] = array('name' => 'devicetype', 'columns' => 'devicetype');

	api_plugin_db_table_create ('routerconfigs', 'plugin_routerconfigs_devices', $data);

	$data = array();

	$data['primary'] = 'id';
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Router Config Device Types';

	$data['columns'][] = array('name' => 'id', 'type' => 'int(11)', 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'name', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'promptuser', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'promptpass', 'type' => 'varchar(256)', 'NULL' => true);
	$data['columns'][] = array('name' => 'connecttype', 'type' => 'varchar(10)', 'NULL' => true);
	$data['columns'][] = array('name' => 'configfile', 'type' => 'varchar(256)', 'NULL' => true);
	$data['columns'][] = array('name' => 'copytftp', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'version', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'promptconfirm', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'confirm', 'type' => 'varchar(64)', 'NULL' => true);
	$data['columns'][] = array('name' => 'sleep', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'timeout', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'forceconfirm', 'type' => 'char(2)', 'NULL' => true, 'default' => 'on');
	$data['columns'][] = array('name' => 'checkendinconfig', 'type' => 'char(2)', 'NULL' => true, 'default' => 'on');
	$data['columns'][] = array('name' => 'anykey', 'type' => 'varchar(50)', 'NULL' => true);
	$data['columns'][] = array('name' => 'elevated', 'type' => 'varchar(3)', 'NULL' => true);

	api_plugin_db_table_create ('routerconfigs', 'plugin_routerconfigs_devicetypes', $data);
	AddDeviceTypes();
}


function AddDeviceTypes() {
	AddDeviceType('Cisco IOS', 'username:', 'password:', 'copy run tftp', 'show version', 'y', '', 'on','');
	AddDeviceType('Cisco CatOS', 'username:', 'password:', 'copy config tftp', '', 'y', 'on', '', '');
	AddDeviceType('Cisco Nexus', 'Username:', 'Password:', 'copy running-config tftp://%SERVER%/%FILE% vrf management', 'show version', '', '', '', '');
	AddDeviceType('HP Comware', 'usernmae:', 'password:', 'startup-configuration to %SERVER% %FILE%', '', '', '', '', 'on');
	AddDeviceType('Dell Switch', 'User', 'Password', 'copy running-config tftp://%SERVER%/%FILE% vrf management', 'show version', 'y', '', '', '', 'Are you sure you want to start');
}

function AddDeviceType($name, $promptuser, $promptpass, $copytftp, $version, $confirm, $forceconfirm, $checkendinconfig, $elevated, $promptconfirm = 'confirm|to tftp:') {
	$params = array( $name, $promptuser, $promptpass, $copytftp, $version, $confirm, $forceconfirm, $checkendinconfig, $elevated, $promptconfirm, $name );
	db_execute_prepared("INSERT INTO plugin_routerconfigs_devicetypes
		(name, promptuser, promptpass, copytftp, version,
		confirm, forceconfirm, checkendinconfig, elevated,
		promptconfirm)
		SELECT
			? AS name, ? AS promptuser, ? AS promptpass, ? AS copytftp, ? AS version,
			? AS confirm, ? AS forceconfirm, ? AS checkendinconfig, ? AS elevated,
			? AS promptconfirm FROM DUAL
		WHERE NOT EXISTS(SELECT * FROM plugin_routerconfigs_devicetypes
			WHERE name = ? LIMIT 1)", $params);
}

function routerconfigs_page_head () {
	global $config;

	if (strpos(get_current_page(), 'router-compare.php')) {
		print '<link rel="stylesheet" type="text/css" href="' . $config['url_path'] . "plugins/routerconfigs/diff.css\">\n";
	}
}

function routerconfigs_poller_bottom () {
	global $config;

	$h = date('G', time());
	$s = date('i', time()) * 60;

	/* Check for the polling interval, only valid with the Multipoller patch */
	$poller_interval = read_config_option('poller_interval');
	if (!isset($poller_interval)) {
		$poller_interval = 300;
	}

	if ($s < $poller_interval) {
		$command_string = trim(read_config_option('path_php_binary'));

		if (trim($command_string) == '') {
			$command_string = 'php';
		}

		$extra_args = ' -q ' . $config['base_path'] . '/plugins/routerconfigs/router-download.php';

		$daily = read_config_option('routerconfigs_hour');
		if ($daily === false || $daily < 0 || $daily > 23) {
			$daily = 0;
		}
		$daily = (int)$daily;

		if ($h != $daily) {
			$extra_args .= ' --retry';
		}

		cacti_log(__("DEBUG: Executing '%s' with arguments '%s'", $command_string, $extra_args, 'routerconfigs'), true, 'RCONFIG', POLLER_VERBOSITY_DEBUG);

		exec_background($command_string, $extra_args);
	}
}

function routerconfigs_config_settings () {
	global $tabs, $settings, $config, $rc_settings;

	routerconfigs_check_upgrade();

	$tabs['routerconfigs'] = __('Router Configs', 'routerconfigs');

	if (isset($settings['routerconfigs'])) {
		$settings['routerconfigs'] = array_merge($settings['routerconfigs'], $rc_settings);
	} else {
		$settings['routerconfigs'] = $rc_settings;
	}
}

function routerconfigs_config_arrays () {
	global $menu;

	plugin_routerconfigs_upgrade();

	if (read_config_option('routerconfigs_presentation') == 'console') {
		$menu[__('Utilities', 'routerconfigs')]['plugins/routerconfigs/router-devices.php'] = __('Router Configs', 'routerconfigs');
	}
}

function routerconfigs_draw_navigation_text ($nav) {
	$nav['router-devices.php:'] = array(
		'title' => __('Router Devices', 'routerconfigs'),
		'mapping' => 'index.php:',
		'url' => 'router-devices.php',
		'level' => '1'
	);

	$nav['router-devices.php:edit'] = array(
		'title' => __('(edit)', 'routerconfigs'),
		'mapping' => 'index.php:,router-devices.php:',
		'url' => 'router-devices.php',
		'level' => '2'
	);

	$nav['router-devices.php:actions'] = array(
		'title' => __('(actions)', 'routerconfigs'),
		'mapping' => 'index.php:,router-devices.php:',
		'url' => 'router-devices.php',
		'level' => '2'
	);

	$nav['router-devices.php:viewconfig'] = array(
		'title' => __('View Config', 'routerconfigs'),
		'mapping' => 'index.php:,router-devices.php:',
		'url' => 'router-devices.php',
		'level' => '2'
	);

	$nav['router-devices.php:viewdebug'] = array(
		'title' => __('View Debug', 'routerconfigs'),
		'mapping' => 'index.php:,router-devices.php:',
		'url' => 'router-devices.php',
		'level' => '2'
	);

	$nav['router-backups.php:'] = array(
		'title' => __('Router Backups', 'routerconfigs'),
		'mapping' => 'index.php:',
		'url' => 'router-backups.php',
		'level' => '1'
	);

	$nav['router-backups.php:edit'] = array(
		'title' => __('(edit)', 'routerconfigs'),
		'mapping' => 'index.php:,router-backups.php:',
		'url' => 'router-backups.php',
		'level' => '2'
	);

	$nav['router-backups.php:actions'] = array(
		'title' => __('(actions)', 'routerconfigs'),
		'mapping' => 'index.php:,router-backups.php:',
		'url' => 'router-backups.php',
		'level' => '2'
	);

	$nav['router-backups.php:viewconfig'] = array(
		'title' => __('View Config', 'routerconfigs'),
		'mapping' => 'index.php:,router-backups.php:',
		'url' => 'router-backups.php',
		'level' => '2'
	);

	$nav['router-accounts.php:'] = array(
		'title' => __('Router Accounts', 'routerconfigs'),
		'mapping' => 'index.php:',
		'url' => 'router-accounts.php',
		'level' => '1'
	);

	$nav['router-accounts.php:edit'] = array(
		'title' => __('(edit)', 'routerconfigs'),
		'mapping' => 'index.php:,router-accounts.php:',
		'url' => 'router-accounts.php',
		'level' => '2'
	);

	$nav['router-accounts.php:actions'] = array(
		'title' => __('(actions)', 'routerconfigs'),
		'mapping' => 'index.php:,router-accounts.php:',
		'url' => 'router-accounts.php',
		'level' => '2'
	);

	$nav['router-compare.php:'] = array(
		'title' => __('Router Compare', 'routerconfigs'),
		'mapping' => 'index.php:',
		'url' => 'router-compare.php',
		'level' => '1'
	);

	return $nav;
}

function plugin_routerconfigs_combinepaths($path1, $path2) {
	if (strlen($path2) < 1 || $path2[0] != '/') {
		if (strlen($path1) && $path1[strlen($path1)- 1] != '/') {
			$path1 = $path1 . '/';
		}
	} else {
		$path1 = '';
	}

	if (strlen($path2) && $path2[strlen($path2)- 1] != '/') {
		$path2 = $path2 . '/';
	}

	return $path1 . $path2;
}

function plugin_routerconfigs_fix_backups_pre14() {
	$backups = db_fetch_assoc('SELECT id, directory, filename FROM plugin_routerconfigs_backups');

	foreach ($backups as $backup) {
		$filename = trim($backup['filename']);
		$path = $backup['directory'];
		if (strlen($path) && $path[strlen($path) - 1] != '/') {
			$path = $path . '/';
		}

		if (strlen($path) < 1 || $path[0] != '/') {
			$path = plugin_routerconfigs_combinepaths(read_config_option('routerconfigs_backup_path'), $path);
		}

		if (basename($filename) != $filename || $path != $backup['directory']) {
			$dir = trim(dirname($filename));
			if ($dir == '.') {
				$dir = '';
			}

			$dir = plugin_routerconfigs_combinepaths($path, $dir);

			db_execute_prepared('UPDATE plugin_routerconfigs_backups
				SET directory = ?, filename = ?
				WHERE id = ?',
				array($dir, basename($filename), $backup['id']));
		}
	}
}

function routerconfigs_show_tab() {
	global $config;

	$tabstyle = read_config_option('routerconfigs_presentation');
	$selected_theme = get_selected_theme();

	if (api_plugin_user_realm_auth('router-devices.php') && $tabstyle == 'toptab') {
		if (preg_match('/router-devices.php/', $_SERVER['REQUEST_URI'], $matches)) {
			$down = true;
		} else {
			$down = false;
		}

		print '<a id="routerconfigs"
			href="' . $config['url_path'] . 'plugins/routerconfigs/router-devices.php">
			<img src="' . ($selected_theme == 'classic' ? get_classic_tabimage(__('Routers', 'routerconfig'), $down):'#') . '" alt="' . __esc('RouterConfigs', 'routerconfigs') . '"></a>';
	}
}

function routerconfigs_device_action_array($device_action_array) {
        $device_action_array['routerconfigs'] = 'Add to Routerconfigs';
        
        return $device_action_array;
}

function routerconfigs_device_action_execute($action) {
        global $config;
        
                
        if ($action != 'routerconfigs') {
                return $action;
        } 
                

        $selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

        if ($selected_items != false) {
                for ($i=0; ($i < count($selected_items)); $i++) {
                       cacti_log("execute item " . $selected_items[$i]);
					   
					   $host=db_fetch_row('SELECT * FROM host WHERE id=' . $selected_items[$i], false);
					   		$save['id'] = '';
		
			cacti_log($host['hostname'] . " " . $host['description']);

			if (isset_request_var('enabled')) {
				$save['enabled'] = 'on';
			} else {
				$save['enabled'] = '';
			}

			$save['hostname']    = str_replace('_', ' ',$host['description']);
			$save['ipaddress']   = $host['hostname'];
			$save['directory']   = get_nfilter_request_var('directory');
			$save['tftpserver']  = get_nfilter_request_var('tftpserver');
			$save['account']     = get_nfilter_request_var('account');
			$save['devicetype']  = get_nfilter_request_var('devicetype');
			$save['schedule']    = get_nfilter_request_var('schedule');
			$save['connecttype'] = get_nfilter_request_var('connecttype');
			$save['timeout']     = get_nfilter_request_var('timeout');
			$save['sleep']       = get_nfilter_request_var('sleep');
			$save['elevated']    = get_nfilter_request_var('elevated');

			$id = sql_save($save, 'plugin_routerconfigs_devices', 'id');
						

                }
        }
                
		raise_message('devices_created', __('Devices created.', 'routerconfigs'), MESSAGE_LEVEL_INFO);

		header('Location: router-devices.php?header=false');
                        
        return $action;
}
                
function routerconfigs_device_action_prepare($save) {
		global $rc_device_edit_fields;
        
		$rc_device_edit_fields_bulk=$rc_device_edit_fields;
		unset($rc_device_edit_fields_bulk['hostname']);
		unset($rc_device_edit_fields_bulk['ipaddress']);
		
        if ($save['drp_action'] != 'routerconfigs') {
                return $save;
        }
		
		$not_list='';
		$list_names=explode('</li>',$save['host_list']);
		$host_list=array();
		$host_array=array();
		
		for($i=0; $i<count($save['host_array']);$i++)
		{
			 $exists=db_fetch_row('select count(*) from host join plugin_routerconfigs_devices on host.hostname = plugin_routerconfigs_devices.ipaddress where host.id=' . $save['host_array'][$i], false);
			//var_dump($exists);
			if ($exists !=false)
			{
				if($exists["count(*)"]=="1")
				{
					$not_list=$not_list . $list_names[$i];
				}
				else
				{
					array_push($host_list,$list_names[$i]);
					array_push($host_array,$save['host_array'][$i]);
				}
			}
			else
			{
				array_push($host_list,$save['host_list'][$i]);
				array_push($host_array,$save['host_array'][$i]);
			}
		}
		//var_dump($list);
		
		$save['host_list']=implode(" ",$host_list);
		$save['host_array']=$host_array;
		
		//var_dump($save);
		print	"<tr>
                <td colspan='2' class='textArea'>
                        <p>" . __('Click \'Continue\' to add all following devices to RouterConfigs.', 'routerconfigs') . "</p>
                        <div class='itemlist'><ul>" . $save['host_list'] . "</ul></div>
                </td>
        </tr>";
		
		if(strlen($not_list)>0)
		{
			print
			"
		<tr>
                <td colspan='2' class='textArea'>
                        <p>" . __('The following devices will not be added to RouterConfigs because there is already a RouterConfigs device with the same IP.', 'routerconfigs') . "</p>
                        <div class='itemlist'><ul>" . $not_list . "</ul></div>
                </td>
        </tr>"
		;}
		
		
		print "<tr><td>";
		
		$account = array(); 
		draw_edit_form(
		array(
			'config' => array('no_form_tag' => true, 'form_name' => 'chk'),
			'fields' => inject_form_variables($rc_device_edit_fields_bulk, $account)
		)
		);
		
		print "</td></tr>";
                                
        return $save;
}
