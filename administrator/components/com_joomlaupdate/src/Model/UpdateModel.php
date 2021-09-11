<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_joomlaupdate
 *
 * @copyright   (C) 2012 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Joomlaupdate\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Updater\Update;
use Joomla\CMS\Updater\Updater;
use Joomla\CMS\User\UserHelper;
use Joomla\CMS\Version;
use Joomla\Database\ParameterType;

/**
 * Joomla! update overview Model
 *
 * @since  2.5.4
 */
class UpdateModel extends BaseDatabaseModel
{
	/**
	 * @var   array  $updateInformation  null
	 * Holds the update information evaluated in getUpdateInformation.
	 *
	 * @since 3.10.0
	 */
	private $updateInformation = null;

	/**
	 * Detects if the Joomla! update site currently in use matches the one
	 * configured in this component. If they don't match, it changes it.
	 *
	 * @return  void
	 *
	 * @since    2.5.4
	 */
	public function applyUpdateSite()
	{
		// Determine the intended update URL.
		$params = ComponentHelper::getParams('com_joomlaupdate');

		switch ($params->get('updatesource', 'nochange'))
		{
			// "Minor & Patch Release for Current version AND Next Major Release".
			case 'next':
				$updateURL = 'https://update.joomla.org/core/sts/list_sts.xml';
				break;

			// "Testing"
			case 'testing':
				$updateURL = 'https://update.joomla.org/core/test/list_test.xml';
				break;

			// "Custom"
			// TODO: check if the customurl is valid and not just "not empty".
			case 'custom':
				if (trim($params->get('customurl', '')) != '')
				{
					$updateURL = trim($params->get('customurl', ''));
				}
				else
				{
					return Factory::getApplication()->enqueueMessage(Text::_('COM_JOOMLAUPDATE_CONFIG_UPDATESOURCE_CUSTOM_ERROR'), 'error');
				}
				break;

			/**
			 * "Minor & Patch Release for Current version (recommended and default)".
			 * The commented "case" below are for documenting where 'default' and legacy options falls
			 * case 'default':
			 * case 'lts':
			 * case 'sts': (Its shown as "Default" cause that option does not exist any more)
			 * case 'nochange':
			 */
			default:
				$updateURL = 'https://update.joomla.org/core/list.xml';
		}

		$id = ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id;
		$db = $this->getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('us') . '.*')
			->from($db->quoteName('#__update_sites_extensions', 'map'))
			->join(
				'INNER',
				$db->quoteName('#__update_sites', 'us'),
				$db->quoteName('us.update_site_id') . ' = ' . $db->quoteName('map.update_site_id')
			)
			->where($db->quoteName('map.extension_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);
		$db->setQuery($query);
		$update_site = $db->loadObject();

		if ($update_site->location != $updateURL)
		{
			// Modify the database record.
			$update_site->last_check_timestamp = 0;
			$update_site->location = $updateURL;
			$db->updateObject('#__update_sites', $update_site, 'update_site_id');

			// Remove cached updates.
			$query->clear()
				->delete($db->quoteName('#__updates'))
				->where($db->quoteName('extension_id') . ' = :id')
				->bind(':id', $id, ParameterType::INTEGER);
			$db->setQuery($query);
			$db->execute();
		}
	}

	/**
	 * Makes sure that the Joomla! update cache is up-to-date.
	 *
	 * @param   boolean  $force  Force reload, ignoring the cache timeout.
	 *
	 * @return  void
	 *
	 * @since    2.5.4
	 */
	public function refreshUpdates($force = false)
	{
		if ($force)
		{
			$cache_timeout = 0;
		}
		else
		{
			$update_params = ComponentHelper::getParams('com_installer');
			$cache_timeout = (int) $update_params->get('cachetimeout', 6);
			$cache_timeout = 3600 * $cache_timeout;
		}

		$updater               = Updater::getInstance();
		$minimumStability      = Updater::STABILITY_STABLE;
		$comJoomlaupdateParams = ComponentHelper::getParams('com_joomlaupdate');

		if (in_array($comJoomlaupdateParams->get('updatesource', 'nochange'), array('testing', 'custom')))
		{
			$minimumStability = $comJoomlaupdateParams->get('minimum_stability', Updater::STABILITY_STABLE);
		}

		$reflection = new \ReflectionObject($updater);
		$reflectionMethod = $reflection->getMethod('findUpdates');
		$methodParameters = $reflectionMethod->getParameters();

		if (count($methodParameters) >= 4)
		{
			// Reinstall support is available in Updater
			$updater->findUpdates(ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id, $cache_timeout, $minimumStability, true);
		}
		else
		{
			$updater->findUpdates(ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id, $cache_timeout, $minimumStability);
		}
	}

	/**
	 * Makes sure that the Joomla! Update Component Update is in the database and check if there is a new version.
	 *
	 * @return  boolean  True if there is an update else false
	 *
	 * @since   4.0.0
	 */
	public function getCheckForSelfUpdate()
	{
		$db = $this->getDbo();

		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_joomlaupdate'));
		$db->setQuery($query);

		try
		{
			// Get the component extension ID
			$joomlaUpdateComponentId = $db->loadResult();
		}
		catch (\RuntimeException $e)
		{
			// Something is wrong here!
			$joomlaUpdateComponentId = 0;
			Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
		}

		// Try the update only if we have an extension id
		if ($joomlaUpdateComponentId != 0)
		{
			// Always force to check for an update!
			$cache_timeout = 0;

			$updater = Updater::getInstance();
			$updater->findUpdates($joomlaUpdateComponentId, $cache_timeout, Updater::STABILITY_STABLE);

			// Fetch the update information from the database.
			$query = $db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__updates'))
				->where($db->quoteName('extension_id') . ' = :id')
				->bind(':id', $joomlaUpdateComponentId, ParameterType::INTEGER);
			$db->setQuery($query);

			try
			{
				$joomlaUpdateComponentObject = $db->loadObject();
			}
			catch (\RuntimeException $e)
			{
				// Something is wrong here!
				$joomlaUpdateComponentObject = null;
				Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
			}

			return !empty($joomlaUpdateComponentObject);
		}

		return false;
	}

	/**
	 * Returns an array with the Joomla! update information.
	 *
	 * @return  array
	 *
	 * @since   2.5.4
	 */
	public function getUpdateInformation()
	{
		if ($this->updateInformation)
		{
			return $this->updateInformation;
		}

		// Initialise the return array.
		$this->updateInformation = array(
			'installed' => \JVERSION,
			'latest'    => null,
			'object'    => null,
			'hasUpdate' => false,
		);

		// Fetch the update information from the database.
		$id = ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id;
		$db = $this->getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__updates'))
			->where($db->quoteName('extension_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);
		$db->setQuery($query);
		$updateObject = $db->loadObject();

		if (is_null($updateObject))
		{
			// We have not found any update in the database - we seem to be running the latest version.
			$this->updateInformation['latest'] = \JVERSION;

			return $this->updateInformation;
		}

		// Check whether this is a valid update or not
		if (version_compare($updateObject->version, JVERSION, '<'))
		{
			// This update points to an outdated version. We should not offer to update to this.
			$this->updateInformation['latest'] = JVERSION;

			return $this->updateInformation;
		}

		$this->updateInformation['latest']  = $updateObject->version;
		$this->updateInformation['current'] = JVERSION;

		// Check whether this is an update or not.
		if (version_compare($updateObject->version, JVERSION, '>'))
		{
			$this->updateInformation['hasUpdate'] = true;
		}

		$minimumStability      = Updater::STABILITY_STABLE;
		$comJoomlaupdateParams = ComponentHelper::getParams('com_joomlaupdate');

		if (in_array($comJoomlaupdateParams->get('updatesource', 'nochange'), array('testing', 'custom')))
		{
			$minimumStability = $comJoomlaupdateParams->get('minimum_stability', Updater::STABILITY_STABLE);
		}

		// Fetch the full update details from the update details URL.
		$update = new Update;
		$update->loadFromXml($updateObject->detailsurl, $minimumStability);

		$this->updateInformation['object'] = $update;

		return $this->updateInformation;
	}

	/**
	 * Removes all of the updates from the table and enable all update streams.
	 *
	 * @return  boolean  Result of operation.
	 *
	 * @since   3.0
	 */
	public function purge()
	{
		$db = $this->getDbo();

		// Modify the database record
		$update_site = new \stdClass;
		$update_site->last_check_timestamp = 0;
		$update_site->enabled = 1;
		$update_site->update_site_id = 1;
		$db->updateObject('#__update_sites', $update_site, 'update_site_id');

		$query = $db->getQuery(true)
			->delete($db->quoteName('#__updates'))
			->where($db->quoteName('update_site_id') . ' = 1');
		$db->setQuery($query);

		if ($db->execute())
		{
			$this->_message = Text::_('COM_JOOMLAUPDATE_CHECKED_UPDATES');

			return true;
		}
		else
		{
			$this->_message = Text::_('COM_JOOMLAUPDATE_FAILED_TO_CHECK_UPDATES');

			return false;
		}
	}

	/**
	 * Downloads the update package to the site.
	 *
	 * @return  array
	 *
	 * @since   2.5.4
	 */
	public function download()
	{
		$updateInfo = $this->getUpdateInformation();
		$packageURL = trim($updateInfo['object']->downloadurl->_data);
		$sources    = $updateInfo['object']->get('downloadSources', array());
		$headers    = get_headers($packageURL, 1);

		// Follow the Location headers until the actual download URL is known
		while (isset($headers['Location']))
		{
			$packageURL = $headers['Location'];
			$headers    = get_headers($packageURL, 1);
		}

		// Remove protocol, path and query string from URL
		$basename = basename($packageURL);

		if (strpos($basename, '?') !== false)
		{
			$basename = substr($basename, 0, strpos($basename, '?'));
		}

		// Find the path to the temp directory and the local package.
		$tempdir  = (string) InputFilter::getInstance(
			[],
			[],
			InputFilter::ONLY_BLOCK_DEFINED_TAGS,
			InputFilter::ONLY_BLOCK_DEFINED_ATTRIBUTES
		)
			->clean(Factory::getApplication()->get('tmp_path'), 'path');
		$target   = $tempdir . '/' . $basename;
		$response = [];

		// Do we have a cached file?
		$exists = File::exists($target);

		if (!$exists)
		{
			// Not there, let's fetch it.
			$mirror = 0;

			while (!($download = $this->downloadPackage($packageURL, $target)) && isset($sources[$mirror]))
			{
				$name       = $sources[$mirror];
				$packageURL = trim($name->url);
				$mirror++;
			}

			$response['basename'] = $download;
		}
		else
		{
			// Is it a 0-byte file? If so, re-download please.
			$filesize = @filesize($target);

			if (empty($filesize))
			{
				$mirror = 0;

				while (!($download = $this->downloadPackage($packageURL, $target)) && isset($sources[$mirror]))
				{
					$name       = $sources[$mirror];
					$packageURL = trim($name->url);
					$mirror++;
				}

				$response['basename'] = $download;
			}

			// Yes, it's there, skip downloading.
			$response['basename'] = $basename;
		}

		$response['check'] = $this->isChecksumValid($target, $updateInfo['object']);

		return $response;
	}

	/**
	 * Return the result of the checksum of a package with the SHA256/SHA384/SHA512 tags in the update server manifest
	 *
	 * @param   string  $packagefile   Location of the package to be installed
	 * @param   Update  $updateObject  The Update Object
	 *
	 * @return  boolean  False in case the validation did not work; true in any other case.
	 *
	 * @note    This method has been forked from (JInstallerHelper::isChecksumValid) so it
	 *          does not depend on an up-to-date InstallerHelper at the update time
	 *
	 * @since   3.9.0
	 */
	private function isChecksumValid($packagefile, $updateObject)
	{
		$hashes = array('sha256', 'sha384', 'sha512');

		foreach ($hashes as $hash)
		{
			if ($updateObject->get($hash, false))
			{
				$hashPackage = hash_file($hash, $packagefile);
				$hashRemote  = $updateObject->$hash->_data;

				if ($hashPackage !== $hashRemote)
				{
					// Return false in case the hash did not match
					return false;
				}
			}
		}

		// Well nothing was provided or all worked
		return true;
	}

	/**
	 * Downloads a package file to a specific directory
	 *
	 * @param   string  $url     The URL to download from
	 * @param   string  $target  The directory to store the file
	 *
	 * @return  boolean True on success
	 *
	 * @since   2.5.4
	 */
	protected function downloadPackage($url, $target)
	{
		try
		{
			Log::add(Text::sprintf('COM_JOOMLAUPDATE_UPDATE_LOG_URL', $url), Log::INFO, 'Update');
		}
		catch (\RuntimeException $exception)
		{
			// Informational log only
		}

		// Make sure the target does not exist.
		File::delete($target);

		// Download the package
		try
		{
			$result = HttpFactory::getHttp([], ['curl', 'stream'])->get($url);
		}
		catch (\RuntimeException $e)
		{
			return false;
		}

		if (!$result || ($result->code != 200 && $result->code != 310))
		{
			return false;
		}

		// Write the file to disk
		File::write($target, $result->body);

		return basename($target);
	}

	/**
	 * Create restoration file and trigger onJoomlaBeforeUpdate event, which find the updated core files
	 * which have changed during the update, where there are override for.
	 *
	 * @param   string  $basename  Optional base path to the file.
	 *
	 * @return  boolean True if successful; false otherwise.
	 *
	 * @since  2.5.4
	 */
	public function createRestorationFile($basename = null)
	{
		// Load overrides plugin.
		PluginHelper::importPlugin('installer');

		// Get a password
		$password = UserHelper::genRandomPassword(32);
		$app = Factory::getApplication();

		// Trigger event before joomla update.
		$app->triggerEvent('onJoomlaBeforeUpdate');

		// Get the absolute path to site's root.
		$siteroot = JPATH_SITE;

		// If the package name is not specified, get it from the update info.
		if (empty($basename))
		{
			$updateInfo = $this->getUpdateInformation();
			$packageURL = $updateInfo['object']->downloadurl->_data;
			$basename = basename($packageURL);
		}

		// Get the package name.
		$config  = $app->getConfig();
		$tempdir = $config->get('tmp_path');
		$file    = $tempdir . '/' . $basename;

		$filesize = @filesize($file);
		$app->setUserState('com_joomlaupdate.password', $password);
		$app->setUserState('com_joomlaupdate.filesize', $filesize);

		$data = "<?php\ndefined('_AKEEBA_RESTORATION') or die('Restricted access');\n";
		$data .= '$restoration_setup = array(' . "\n";
		$data .= <<<ENDDATA
	'kickstart.security.password' => '$password',
	'kickstart.tuning.max_exec_time' => '5',
	'kickstart.tuning.run_time_bias' => '75',
	'kickstart.tuning.min_exec_time' => '0',
	'kickstart.procengine' => 'direct',
	'kickstart.setup.sourcefile' => '$file',
	'kickstart.setup.destdir' => '$siteroot',
	'kickstart.setup.restoreperms' => '0',
	'kickstart.setup.filetype' => 'zip',
	'kickstart.setup.dryrun' => '0',
	'kickstart.setup.renamefiles' => array(),
	'kickstart.setup.postrenamefiles' => false
ENDDATA;

		$data .= ');';

		// Remove the old file, if it's there...
		$configpath = JPATH_COMPONENT_ADMINISTRATOR . '/restoration.php';

		if (File::exists($configpath))
		{
			File::delete($configpath);
		}

		// Write new file. First try with File.
		$result = File::write($configpath, $data);

		// In case File used FTP but direct access could help.
		if (!$result)
		{
			if (function_exists('file_put_contents'))
			{
				$result = @file_put_contents($configpath, $data);

				if ($result !== false)
				{
					$result = true;
				}
			}
			else
			{
				$fp = @fopen($configpath, 'wt');

				if ($fp !== false)
				{
					$result = @fwrite($fp, $data);

					if ($result !== false)
					{
						$result = true;
					}

					@fclose($fp);
				}
			}
		}

		return $result;
	}

	/**
	 * Runs the schema update SQL files, the PHP update script and updates the
	 * manifest cache and #__extensions entry. Essentially, it is identical to
	 * InstallerFile::install() without the file copy.
	 *
	 * @return  boolean True on success.
	 *
	 * @since   2.5.4
	 */
	public function finaliseUpgrade()
	{
		$installer = Installer::getInstance();

		$manifest = $installer->isManifest(JPATH_MANIFESTS . '/files/joomla.xml');

		if ($manifest === false)
		{
			$installer->abort(Text::_('JLIB_INSTALLER_ABORT_DETECTMANIFEST'));

			return false;
		}

		$installer->manifest = $manifest;

		$installer->setUpgrade(true);
		$installer->setOverwrite(true);

		$installer->extension = new \Joomla\CMS\Table\Extension($this->getDbo());
		$installer->extension->load(ExtensionHelper::getExtensionRecord('joomla', 'file')->extension_id);

		$installer->setAdapter($installer->extension->type);

		$installer->setPath('manifest', JPATH_MANIFESTS . '/files/joomla.xml');
		$installer->setPath('source', JPATH_MANIFESTS . '/files');
		$installer->setPath('extension_root', JPATH_ROOT);

		// Run the script file.
		\JLoader::register('JoomlaInstallerScript', JPATH_ADMINISTRATOR . '/components/com_admin/script.php');

		$manifestClass = new \JoomlaInstallerScript;

		ob_start();
		ob_implicit_flush(false);

		if ($manifestClass && method_exists($manifestClass, 'preflight'))
		{
			if ($manifestClass->preflight('update', $installer) === false)
			{
				$installer->abort(
					Text::sprintf(
						'JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE',
						Text::_('JLIB_INSTALLER_INSTALL')
					)
				);

				return false;
			}
		}

		// Create msg object; first use here.
		$msg = ob_get_contents();
		ob_end_clean();

		// Get a database connector object.
		$db = $this->getDbo();

		/*
		 * Check to see if a file extension by the same name is already installed.
		 * If it is, then update the table because if the files aren't there
		 * we can assume that it was (badly) uninstalled.
		 * If it isn't, add an entry to extensions.
		 */
		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('file'))
			->where($db->quoteName('element') . ' = ' . $db->quote('joomla'));
		$db->setQuery($query);

		try
		{
			$db->execute();
		}
		catch (\RuntimeException $e)
		{
			// Install failed, roll back changes.
			$installer->abort(
				Text::sprintf('JLIB_INSTALLER_ABORT_FILE_ROLLBACK', Text::_('JLIB_INSTALLER_UPDATE'), $e->getMessage())
			);

			return false;
		}

		$id = $db->loadResult();
		$row = new \Joomla\CMS\Table\Extension($this->getDbo());

		if ($id)
		{
			// Load the entry and update the manifest_cache.
			$row->load($id);

			// Update name.
			$row->set('name', 'files_joomla');

			// Update manifest.
			$row->manifest_cache = $installer->generateManifestCache();

			if (!$row->store())
			{
				// Install failed, roll back changes.
				$installer->abort(
					Text::sprintf('JLIB_INSTALLER_ABORT_FILE_ROLLBACK', Text::_('JLIB_INSTALLER_UPDATE'), $row->getError())
				);

				return false;
			}
		}
		else
		{
			// Add an entry to the extension table with a whole heap of defaults.
			$row->set('name', 'files_joomla');
			$row->set('type', 'file');
			$row->set('element', 'joomla');

			// There is no folder for files so leave it blank.
			$row->set('folder', '');
			$row->set('enabled', 1);
			$row->set('protected', 0);
			$row->set('access', 0);
			$row->set('client_id', 0);
			$row->set('params', '');
			$row->set('manifest_cache', $installer->generateManifestCache());

			if (!$row->store())
			{
				// Install failed, roll back changes.
				$installer->abort(Text::sprintf('JLIB_INSTALLER_ABORT_FILE_INSTALL_ROLLBACK', $row->getError()));

				return false;
			}

			// Set the insert id.
			$row->set('extension_id', $db->insertid());

			// Since we have created a module item, we add it to the installation step stack
			// so that if we have to rollback the changes we can undo it.
			$installer->pushStep(array('type' => 'extension', 'extension_id' => $row->extension_id));
		}

		$result = $installer->parseSchemaUpdates($manifest->update->schemas, $row->extension_id);

		if ($result === false)
		{
			// Install failed, rollback changes (message already logged by the installer).
			$installer->abort();

			return false;
		}

		// Reinitialise the installer's extensions table's properties.
		$installer->extension->getFields(true);

		// Start Joomla! 1.6.
		ob_start();
		ob_implicit_flush(false);

		if ($manifestClass && method_exists($manifestClass, 'update'))
		{
			if ($manifestClass->update($installer) === false)
			{
				// Install failed, rollback changes.
				$installer->abort(
					Text::sprintf(
						'JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE',
						Text::_('JLIB_INSTALLER_INSTALL')
					)
				);

				return false;
			}
		}

		// Append messages.
		$msg .= ob_get_contents();
		ob_end_clean();

		// Clobber any possible pending updates.
		$update = new \Joomla\CMS\Table\Update($this->getDbo());
		$uid = $update->find(
			array('element' => 'joomla', 'type' => 'file', 'client_id' => '0', 'folder' => '')
		);

		if ($uid)
		{
			$update->delete($uid);
		}

		// And now we run the postflight.
		ob_start();
		ob_implicit_flush(false);

		if ($manifestClass && method_exists($manifestClass, 'postflight'))
		{
			$manifestClass->postflight('update', $installer);
		}

		// Append messages.
		$msg .= ob_get_contents();
		ob_end_clean();

		if ($msg != '')
		{
			$installer->set('extension_message', $msg);
		}

		// Refresh versionable assets cache.
		Factory::getApplication()->flushAssets();

		return true;
	}

	/**
	 * Removes the extracted package file and trigger onJoomlaAfterUpdate event, which find the updated core files
	 * which have changed during the update, where there are override for.
	 *
	 * @return  void
	 *
	 * @since   2.5.4
	 */
	public function cleanUp()
	{
		// Load overrides plugin.
		PluginHelper::importPlugin('installer');

		$app = Factory::getApplication();

		// Trigger event after joomla update.
		$app->triggerEvent('onJoomlaAfterUpdate');

		// Remove the update package.
		$tempdir = $app->get('tmp_path');

		$file = $app->getUserState('com_joomlaupdate.file', null);
		File::delete($tempdir . '/' . $file);

		// Remove the restoration.php file.
		File::delete(JPATH_COMPONENT_ADMINISTRATOR . '/restoration.php');

		// Remove joomla.xml from the site's root.
		File::delete(JPATH_ROOT . '/joomla.xml');

		// Unset the update filename from the session.
		$app = Factory::getApplication();
		$app->setUserState('com_joomlaupdate.file', null);
		$oldVersion = $app->getUserState('com_joomlaupdate.oldversion');

		// Trigger event after joomla update.
		$app->triggerEvent('onJoomlaAfterUpdate', array($oldVersion));
		$app->setUserState('com_joomlaupdate.oldversion', null);
	}

	/**
	 * Uploads what is presumably an update ZIP file under a mangled name in the temporary directory.
	 *
	 * @return  void
	 *
	 * @since   3.6.0
	 */
	public function upload()
	{
		// Get the uploaded file information.
		$input = Factory::getApplication()->input;

		// Do not change the filter type 'raw'. We need this to let files containing PHP code to upload. See \JInputFiles::get.
		$userfile = $input->files->get('install_package', null, 'raw');

		// Make sure that file uploads are enabled in php.
		if (!(bool) ini_get('file_uploads'))
		{
			throw new \RuntimeException(Text::_('COM_INSTALLER_MSG_INSTALL_WARNINSTALLFILE'), 500);
		}

		// Make sure that zlib is loaded so that the package can be unpacked.
		if (!extension_loaded('zlib'))
		{
			throw new \RuntimeException(Text::_('COM_INSTALLER_MSG_INSTALL_WARNINSTALLZLIB'), 500);
		}

		// If there is no uploaded file, we have a problem...
		if (!is_array($userfile))
		{
			throw new \RuntimeException(Text::_('COM_INSTALLER_MSG_INSTALL_NO_FILE_SELECTED'), 500);
		}

		// Is the PHP tmp directory missing?
		if ($userfile['error'] && ($userfile['error'] == UPLOAD_ERR_NO_TMP_DIR))
		{
			throw new \RuntimeException(
				Text::_('COM_INSTALLER_MSG_INSTALL_WARNINSTALLUPLOADERROR') . '<br>' .
				Text::_('COM_INSTALLER_MSG_WARNINGS_PHPUPLOADNOTSET'),
				500
			);
		}

		// Is the max upload size too small in php.ini?
		if ($userfile['error'] && ($userfile['error'] == UPLOAD_ERR_INI_SIZE))
		{
			throw new \RuntimeException(
				Text::_('COM_INSTALLER_MSG_INSTALL_WARNINSTALLUPLOADERROR') . '<br>' . Text::_('COM_INSTALLER_MSG_WARNINGS_SMALLUPLOADSIZE'),
				500
			);
		}

		// Check if there was a different problem uploading the file.
		if ($userfile['error'] || $userfile['size'] < 1)
		{
			throw new \RuntimeException(Text::_('COM_INSTALLER_MSG_INSTALL_WARNINSTALLUPLOADERROR'), 500);
		}

		// Build the appropriate paths.
		$tmp_dest = tempnam(Factory::getApplication()->get('tmp_path'), 'ju');
		$tmp_src  = $userfile['tmp_name'];

		// Move uploaded file.
		$result = File::upload($tmp_src, $tmp_dest, false, true);

		if (!$result)
		{
			throw new \RuntimeException(Text::_('COM_INSTALLER_MSG_INSTALL_WARNINSTALLUPLOADERROR'), 500);
		}

		Factory::getApplication()->setUserState('com_joomlaupdate.temp_file', $tmp_dest);
	}

	/**
	 * Checks the super admin credentials are valid for the currently logged in users
	 *
	 * @param   array  $credentials  The credentials to authenticate the user with
	 *
	 * @return  boolean
	 *
	 * @since   3.6.0
	 */
	public function captiveLogin($credentials)
	{
		// Make sure the username matches
		$username = $credentials['username'] ?? null;
		$user     = Factory::getUser();

		if (strtolower($user->username) != strtolower($username))
		{
			return false;
		}

		// Make sure the user is authorised
		if (!$user->authorise('core.admin'))
		{
			return false;
		}

		// Get the global Authentication object.
		$authenticate = Authentication::getInstance();
		$response     = $authenticate->authenticate($credentials);

		if ($response->status !== Authentication::STATUS_SUCCESS)
		{
			return false;
		}

		return true;
	}

	/**
	 * Does the captive (temporary) file we uploaded before still exist?
	 *
	 * @return  boolean
	 *
	 * @since   3.6.0
	 */
	public function captiveFileExists()
	{
		$file = Factory::getApplication()->getUserState('com_joomlaupdate.temp_file', null);

		if (empty($file) || !File::exists($file))
		{
			return false;
		}

		return true;
	}

	/**
	 * Remove the captive (temporary) file we uploaded before and the .
	 *
	 * @return  void
	 *
	 * @since   3.6.0
	 */
	public function removePackageFiles()
	{
		$files = array(
			Factory::getApplication()->getUserState('com_joomlaupdate.temp_file', null),
			Factory::getApplication()->getUserState('com_joomlaupdate.file', null),
		);

		foreach ($files as $file)
		{
			if (File::exists($file))
			{
				File::delete($file);
			}
		}
	}

	/**
	 * Gets PHP options.
	 * TODO: Outsource, build common code base for pre install and pre update check
	 *
	 * @return array Array of PHP config options
	 *
	 * @since   3.10.0
	 */
	public function getPhpOptions()
	{
		$options = array();

		/*
		 * Check the PHP Version. It is already checked in Update.
		 * A Joomla! Update which is not supported by current PHP
		 * version is not shown. So this check is actually unnecessary.
		 */
		$option         = new \stdClass;
		$option->label  = Text::sprintf('INSTL_PHP_VERSION_NEWER', $this->getTargetMinimumPHPVersion());
		$option->state  = $this->isPhpVersionSupported();
		$option->notice = null;
		$options[]      = $option;

		// Check for zlib support.
		$option         = new \stdClass;
		$option->label  = Text::_('INSTL_ZLIB_COMPRESSION_SUPPORT');
		$option->state  = extension_loaded('zlib');
		$option->notice = null;
		$options[]      = $option;

		// Check for XML support.
		$option         = new \stdClass;
		$option->label  = Text::_('INSTL_XML_SUPPORT');
		$option->state  = extension_loaded('xml');
		$option->notice = null;
		$options[]      = $option;

		// Check for mbstring options.
		if (extension_loaded('mbstring'))
		{
			// Check for default MB language.
			$option = new \stdClass;
			$option->label  = Text::_('INSTL_MB_LANGUAGE_IS_DEFAULT');
			$option->state  = strtolower(ini_get('mbstring.language')) === 'neutral';
			$option->notice = $option->state ? null : Text::_('INSTL_NOTICEMBLANGNOTDEFAULT');
			$options[] = $option;

			// Check for MB function overload.
			$option = new \stdClass;
			$option->label  = Text::_('INSTL_MB_STRING_OVERLOAD_OFF');
			$option->state  = ini_get('mbstring.func_overload') == 0;
			$option->notice = $option->state ? null : Text::_('INSTL_NOTICEMBSTRINGOVERLOAD');
			$options[] = $option;
		}

		// Check for a missing native parse_ini_file implementation.
		$option = new \stdClass;
		$option->label  = Text::_('INSTL_PARSE_INI_FILE_AVAILABLE');
		$option->state  = $this->getIniParserAvailability();
		$option->notice = null;
		$options[] = $option;

		// Check for missing native json_encode / json_decode support.
		$option = new \stdClass;
		$option->label  = Text::_('INSTL_JSON_SUPPORT_AVAILABLE');
		$option->state  = function_exists('json_encode') && function_exists('json_decode');
		$option->notice = null;
		$options[] = $option;
		$updateInformation = $this->getUpdateInformation();

		// Check if configured database is compatible with the next major version of Joomla
		$nextMajorVersion = Version::MAJOR_VERSION + 1;

		if (version_compare($updateInformation['latest'], (string) $nextMajorVersion, '>='))
		{
			$option = new \stdClass;
			$option->label  = Text::sprintf('INSTL_DATABASE_SUPPORTED', $this->getConfiguredDatabaseType());
			$option->state  = $this->isDatabaseTypeSupported();
			$option->notice = null;
			$options[]      = $option;
		}

		// Check if database structure is up to date
		$option = new \stdClass;
		$option->label  = Text::_('COM_JOOMLAUPDATE_VIEW_DEFAULT_DATABASE_STRUCTURE_TITLE');
		$option->state  = $this->getDatabaseSchemaCheck();
		$option->notice = $option->state ? null : Text::_('COM_JOOMLAUPDATE_VIEW_DEFAULT_DATABASE_STRUCTURE_NOTICE');
		$options[] = $option;

		return $options;
	}

	/**
	 * Gets PHP Settings.
	 * TODO: Outsource, build common code base for pre install and pre update check
	 *
	 * @return  array
	 *
	 * @since   3.10.0
	 */
	public function getPhpSettings()
	{
		$settings = array();

		// Check for display errors.
		$setting = new \stdClass;
		$setting->label = Text::_('INSTL_DISPLAY_ERRORS');
		$setting->state = (bool) ini_get('display_errors');
		$setting->recommended = false;
		$settings[] = $setting;

		// Check for file uploads.
		$setting = new \stdClass;
		$setting->label = Text::_('INSTL_FILE_UPLOADS');
		$setting->state = (bool) ini_get('file_uploads');
		$setting->recommended = true;
		$settings[] = $setting;

		// Check for output buffering.
		$setting = new \stdClass;
		$setting->label = Text::_('INSTL_OUTPUT_BUFFERING');
		$setting->state = (int) ini_get('output_buffering') !== 0;
		$setting->recommended = false;
		$settings[] = $setting;

		// Check for session auto-start.
		$setting = new \stdClass;
		$setting->label = Text::_('INSTL_SESSION_AUTO_START');
		$setting->state = (bool) ini_get('session.auto_start');
		$setting->recommended = false;
		$settings[] = $setting;

		// Check for native ZIP support.
		$setting = new \stdClass;
		$setting->label = Text::_('INSTL_ZIP_SUPPORT_AVAILABLE');
		$setting->state = function_exists('zip_open') && function_exists('zip_read');
		$setting->recommended = true;
		$settings[] = $setting;

		// Check for GD support
		$setting = new \stdClass;
		$setting->label = Text::sprintf('INSTL_EXTENSION_AVAILABLE', 'GD');
		$setting->state = extension_loaded('gd');
		$setting->recommended = true;
		$settings[] = $setting;

		// Check for iconv support
		$setting = new \stdClass;
		$setting->label = Text::sprintf('INSTL_EXTENSION_AVAILABLE', 'iconv');
		$setting->state = function_exists('iconv');
		$setting->recommended = true;
		$settings[] = $setting;

		// Check for intl support
		$setting = new \stdClass;
		$setting->label = Text::sprintf('INSTL_EXTENSION_AVAILABLE', 'intl');
		$setting->state = function_exists('transliterator_transliterate');
		$setting->recommended = true;
		$settings[] = $setting;

		return $settings;
	}

	/**
	 * Returns the configured database type id (mysqli or sqlsrv or ...)
	 *
	 * @return string
	 *
	 * @since 3.10.0
	 */
	private function getConfiguredDatabaseType()
	{
		return Factory::getApplication()->get('dbtype');
	}

	/**
	 * Returns true, if J! version is < 4 or current configured
	 * database type is compatible with the update.
	 *
	 * @return boolean
	 *
	 * @since 3.10.0
	 */
	public function isDatabaseTypeSupported()
	{
		$updateInformation = $this->getUpdateInformation();
		$nextMajorVersion  = Version::MAJOR_VERSION + 1;

		// Check if configured database is compatible with Joomla 4
		if (version_compare($updateInformation['latest'], (string) $nextMajorVersion, '>='))
		{
			$unsupportedDatabaseTypes = array('sqlsrv', 'sqlazure');
			$currentDatabaseType = $this->getConfiguredDatabaseType();

			return !in_array($currentDatabaseType, $unsupportedDatabaseTypes);
		}

		return true;
	}


	/**
	 * Returns true, if current installed php version is compatible with the update.
	 *
	 * @return boolean
	 *
	 * @since 3.10.0
	 */
	public function isPhpVersionSupported()
	{
		return version_compare(PHP_VERSION, $this->getTargetMinimumPHPVersion(), '>=');
	}

	/**
	 * Returns the PHP minimum version for the update.
	 * Returns JOOMLA_MINIMUM_PHP, if there is no information given.
	 *
	 * @return string
	 *
	 * @since 3.10.0
	 */
	private function getTargetMinimumPHPVersion()
	{
		$updateInformation = $this->getUpdateInformation();

		return isset($updateInformation['object']->php_minimum) ?
			$updateInformation['object']->php_minimum->_data :
			JOOMLA_MINIMUM_PHP;
	}

	/**
	 * Checks the availability of the parse_ini_file and parse_ini_string functions.
	 * TODO: Outsource, build common code base for pre install and pre update check
	 *
	 * @return  boolean  True if the method exists.
	 *
	 * @since   3.10.0
	 */
	public function getIniParserAvailability()
	{
		$disabledFunctions = ini_get('disable_functions');

		if (!empty($disabledFunctions))
		{
			// Attempt to detect them in the PHP INI disable_functions variable.
			$disabledFunctions = explode(',', trim($disabledFunctions));
			$numberOfDisabledFunctions = count($disabledFunctions);

			for ($i = 0; $i < $numberOfDisabledFunctions; $i++)
			{
				$disabledFunctions[$i] = trim($disabledFunctions[$i]);
			}

			$result = !in_array('parse_ini_string', $disabledFunctions);
		}
		else
		{
			// Attempt to detect their existence; even pure PHP implementations of them will trigger a positive response, though.
			$result = function_exists('parse_ini_string');
		}

		return $result;
	}


	/**
	 * Check if database structure is up to date
	 *
	 * @return  boolean  True if ok, false if not.
	 *
	 * @since   3.10.0
	 */
	private function getDatabaseSchemaCheck(): bool
	{
		$mvcFactory = $this->bootComponent('com_installer')->getMVCFactory();

		/** @var \Joomla\Component\Installer\Administrator\Model\DatabaseModel $model */
		$model = $mvcFactory->createModel('Database', 'Administrator');

		// Check if no default text filters found
		if (!$model->getDefaultTextFilters())
		{
			return false;
		}

		$coreExtensionInfo = \Joomla\CMS\Extension\ExtensionHelper::getExtensionRecord('joomla', 'file');
		$cache = new \Joomla\Registry\Registry($coreExtensionInfo->manifest_cache);

		$updateVersion = $cache->get('version');

		// Check if database update version does not match CMS version
		if (version_compare($updateVersion, JVERSION) != 0)
		{
			return false;
		}

		// Ensure we only get information for core
		$model->setState('filter.extension_id', $coreExtensionInfo->extension_id);

		// We're filtering by a single extension which must always exist - so can safely access this through
		// element 0 of the array
		$changeInformation = $model->getItems()[0];

		// Check if schema errors found
		if ($changeInformation['errorsCount'] !== 0)
		{
			return false;
		}

		// Check if database schema version does not match CMS version
		if ($model->getSchemaVersion($coreExtensionInfo->extension_id) != $changeInformation['schema'])
		{
			return false;
		}

		// No database problems found
		return true;
	}

	/**
	 * Gets an array containing all installed extensions, that are not core extensions.
	 *
	 * @return  array  name,version,updateserver
	 *
	 * @since   3.10.0
	 */
	public function getNonCoreExtensions()
	{
		$db = $this->getDbo();
		$query = $db->getQuery(true);

		$query->select(
			[
				$db->quoteName('ex.name'),
				$db->quoteName('ex.extension_id'),
				$db->quoteName('ex.manifest_cache'),
				$db->quoteName('ex.type'),
				$db->quoteName('ex.folder'),
				$db->quoteName('ex.element'),
				$db->quoteName('ex.client_id'),
			]
		)
			->from($db->quoteName('#__extensions', 'ex'))
			->where($db->quoteName('ex.package_id') . ' = 0')
			->whereNotIn($db->quoteName('ex.extension_id'), ExtensionHelper::getCoreExtensionIds());

		$db->setQuery($query);
		$rows = $db->loadObjectList();

		foreach ($rows as $extension)
		{
			$decode = json_decode($extension->manifest_cache);

			// Remove unused fields so they do not cause javascript errors during pre-update check
			unset($decode->description);
			unset($decode->copyright);
			unset($decode->creationDate);

			$this->translateExtensionName($extension);
			$extension->version
				= isset($decode->version) ? $decode->version : Text::_('COM_JOOMLAUPDATE_PREUPDATE_UNKNOWN_EXTENSION_MANIFESTCACHE_VERSION');
			unset($extension->manifest_cache);
			$extension->manifest_cache = $decode;
		}

		return $rows;
	}

	/**
	 * Gets an array containing all installed and enabled plugins, that are not core plugins.
	 *
	 * @param   array  $folderFilter  Limit the list of plugins to a specific set of folder values
	 *
	 * @return  array  name,version,updateserver
	 *
	 * @since   3.10.0
	 */
	public function getNonCorePlugins($folderFilter = ['system','user','authentication','actionlog','twofactorauth'])
	{
		$db    = $this->getDbo();
		$query = $db->getQuery(true);

		$query->select(
			$db->qn('ex.name') . ', ' .
			$db->qn('ex.extension_id') . ', ' .
			$db->qn('ex.manifest_cache') . ', ' .
			$db->qn('ex.type') . ', ' .
			$db->qn('ex.folder') . ', ' .
			$db->qn('ex.element') . ', ' .
			$db->qn('ex.client_id') . ', ' .
			$db->qn('ex.package_id')
		)->from(
			$db->qn('#__extensions', 'ex')
		)->where(
			$db->qn('ex.type') . ' = ' . $db->quote('plugin')
		)->where(
			$db->qn('ex.enabled') . ' = 1'
		)->whereNotIn(
			$db->quoteName('ex.extension_id'), ExtensionHelper::getCoreExtensionIds()
		);

		if (count($folderFilter) > 0)
		{
			$folderFilter = array_map(array($db, 'quote'), $folderFilter);

			$query->where($db->qn('folder') . ' IN (' . implode(',', $folderFilter) . ')');
		}

		$db->setQuery($query);
		$rows = $db->loadObjectList();

		foreach ($rows as $plugin)
		{
			$decode = json_decode($plugin->manifest_cache);

			// Remove unused fields so they do not cause javascript errors during pre-update check
			unset($decode->description);
			unset($decode->copyright);
			unset($decode->creationDate);

			$this->translateExtensionName($plugin);
			$plugin->version = $decode->version ?? Text::_('COM_JOOMLAUPDATE_PREUPDATE_UNKNOWN_EXTENSION_MANIFESTCACHE_VERSION');
			unset($plugin->manifest_cache);
			$plugin->manifest_cache = $decode;
		}

		return $rows;
	}

	/**
	 * Called by controller's fetchExtensionCompatibility, which is called via AJAX.
	 *
	 * @param   string  $extensionID          The ID of the checked extension
	 * @param   string  $joomlaTargetVersion  Target version of Joomla
	 *
	 * @return object
	 *
	 * @since 3.10.0
	 */
	public function fetchCompatibility($extensionID, $joomlaTargetVersion)
	{
		$updateSites = $this->getUpdateSitesInfo($extensionID);

		if (empty($updateSites))
		{
			return (object) array('state' => 2);
		}

		foreach ($updateSites as $updateSite)
		{
			if ($updateSite['type'] === 'collection')
			{
				$updateFileUrls = $this->getCollectionDetailsUrls($updateSite, $joomlaTargetVersion);

				foreach ($updateFileUrls as $updateFileUrl)
				{
					$compatibleVersion = $this->checkCompatibility($updateFileUrl, $joomlaTargetVersion);

					if ($compatibleVersion)
					{
						// Return the compatible version
						return (object) array('state' => 1, 'compatibleVersion' => $compatibleVersion->_data);
					}
					else
					{
						// Return the compatible version as false so we can say update server is supported but no compatible version found
						return (object) array('state' => 1, 'compatibleVersion' => false);
					}
				}
			}
			else
			{
				$compatibleVersion = $this->checkCompatibility($updateSite['location'], $joomlaTargetVersion);

				if ($compatibleVersion)
				{
					// Return the compatible version
					return (object) array('state' => 1, 'compatibleVersion' => $compatibleVersion->_data);
				}
				else
				{
					// Return the compatible version as false so we can say update server is supported but no compatible version found
					return (object) array('state' => 1, 'compatibleVersion' => false);
				}
			}
		}

		// In any other case we mark this extension as not compatible
		return (object) array('state' => 0);
	}

	/**
	 * Returns records with update sites and extension information for a given extension ID.
	 *
	 * @param   int  $extensionID  The extension ID
	 *
	 * @return  array
	 *
	 * @since 3.10.0
	 */
	private function getUpdateSitesInfo($extensionID)
	{
		$id = (int) $extensionID;
		$db = $this->getDbo();
		$query = $db->getQuery(true);

		$query->select(
			$db->qn('us.type') . ', ' .
			$db->qn('us.location') . ', ' .
			$db->qn('e.element') . ' AS ' . $db->qn('ext_element') . ', ' .
			$db->qn('e.type') . ' AS ' . $db->qn('ext_type') . ', ' .
			$db->qn('e.folder') . ' AS ' . $db->qn('ext_folder')
		)
			->from($db->quoteName('#__update_sites', 'us'))
			->join(
				'LEFT',
				$db->quoteName('#__update_sites_extensions', 'ue'),
				$db->quoteName('ue.update_site_id') . ' = ' . $db->quoteName('us.update_site_id')
			)
			->join(
				'LEFT',
				$db->quoteName('#__extensions', 'e'),
				$db->quoteName('e.extension_id') . ' = ' . $db->quoteName('ue.extension_id')
			)
			->where($db->quoteName('e.extension_id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		$db->setQuery($query);

		$result = $db->loadAssocList();

		if (!is_array($result))
		{
			return array();
		}

		return $result;
	}

	/**
	 * Method to get details URLs from a collection update site for given extension and Joomla target version.
	 *
	 * @param   array   $updateSiteInfo       The update site and extension information record to process
	 * @param   string  $joomlaTargetVersion  The Joomla! version to test against,
	 *
	 * @return  array  An array of URLs.
	 *
	 * @since   3.10.0
	 */
	private function getCollectionDetailsUrls($updateSiteInfo, $joomlaTargetVersion)
	{
		$return = array();

		$http = new Http;

		try
		{
			$response = $http->get($updateSiteInfo['location']);
		}
		catch (\RuntimeException $e)
		{
			$response = null;
		}

		if ($response === null || $response->code !== 200)
		{
			return $return;
		}

		$updateSiteXML = simplexml_load_string($response->body);

		foreach ($updateSiteXML->extension as $extension)
		{
			$attribs = new \stdClass;

			$attribs->element               = '';
			$attribs->type                  = '';
			$attribs->folder                = '';
			$attribs->targetplatformversion = '';

			foreach ($extension->attributes() as $key => $value)
			{
				$attribs->$key = (string) $value;
			}

			if ($attribs->element === $updateSiteInfo['ext_element']
				&& $attribs->type === $updateSiteInfo['ext_type']
				&& $attribs->folder === $updateSiteInfo['ext_folder']
				&& preg_match('/^' . $attribs->targetplatformversion . '/', $joomlaTargetVersion))
			{
				$return[] = (string) $extension['detailsurl'];
			}
		}

		return $return;
	}

	/**
	 * Method to check non core extensions for compatibility.
	 *
	 * @param   string  $updateFileUrl        The items update XML url.
	 * @param   string  $joomlaTargetVersion  The Joomla! version to test against
	 *
	 * @return  mixed  An array of data items or false.
	 *
	 * @since   3.10.0
	 */
	private function checkCompatibility($updateFileUrl, $joomlaTargetVersion)
	{
		$minimumStability = ComponentHelper::getParams('com_installer')->get('minimum_stability', Updater::STABILITY_STABLE);

		$update = new Update;
		$update->set('jversion.full', $joomlaTargetVersion);
		$update->loadFromXml($updateFileUrl, $minimumStability);

		$downloadUrl = $update->get('downloadurl');

		return !empty($downloadUrl) && !empty($downloadUrl->_data) ? $update->get('version') : false;
	}

	/**
	 * Translates an extension name
	 *
	 * @param   object  &$item  The extension of which the name needs to be translated
	 *
	 * @return  void
	 *
	 * @since   3.10.0
	 */
	protected function translateExtensionName(&$item)
	{
		// ToDo: Cleanup duplicated code. from com_installer/models/extension.php
		$lang = Factory::getLanguage();
		$path = $item->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE;

		$extension = $item->element;
		$source = JPATH_SITE;

		switch ($item->type)
		{
			case 'component':
				$extension = $item->element;
				$source = $path . '/components/' . $extension;
				break;
			case 'module':
				$extension = $item->element;
				$source = $path . '/modules/' . $extension;
				break;
			case 'file':
				$extension = 'files_' . $item->element;
				break;
			case 'library':
				$extension = 'lib_' . $item->element;
				break;
			case 'plugin':
				$extension = 'plg_' . $item->folder . '_' . $item->element;
				$source = JPATH_PLUGINS . '/' . $item->folder . '/' . $item->element;
				break;
			case 'template':
				$extension = 'tpl_' . $item->element;
				$source = $path . '/templates/' . $item->element;
		}

		$lang->load("$extension.sys", JPATH_ADMINISTRATOR)
		|| $lang->load("$extension.sys", $source);
		$lang->load($extension, JPATH_ADMINISTRATOR)
		|| $lang->load($extension, $source);

		// Translate the extension name if possible
		$item->name = strip_tags(Text::_($item->name));
	}
}