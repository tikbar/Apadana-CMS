<?php
/**
 * @In the name of God!
 * @author: Apadana Development Team
 * @email: info@apadanacms.ir
 * @link: http://www.apadanacms.ir
 * @license: http://www.gnu.org/licenses/
 * @copyright: Copyright © 2012-2014 ApadanaCms.ir. All rights reserved.
 * @Apadana CMS is a Free Software
 */

defined('security') or exit('Direct Access to this location is not allowed.');

function module_counter_info()
{
	return array(
		'name' => 'counter',
		'version' => '1.0',
		'creationDate' => '2012-07-21 18:00:03',
		'description' => 'ماژول آمارگیر برای آپادانا.',
		'author' => 'Apadana Development Team',
		'authorEmail' => 'info@apadanacms.ir',
		'authorUrl' => 'http://www.apadanacms.ir',
		'license' => 'GNU/GPL',
	);
}

function module_counter_admin()
{
	member::check_admin_page_access('counter') or warning('عدم دسترسی!', 'شما دسترسی لازم برای مشاهده این بخش را ندارید!');
	require_once(root_dir.'modules/counter/functions.admin.php');
	
	_default();
}

?>